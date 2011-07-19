<?php

require_once './include/api/forums.php';

/**
 * Handle "mark read" functionality for forums that have subforums.
 */
function phorum_mod_subforums_page_index()
{
    global $PHORUM;

    // Check if we have to handle "mark read".
    if (empty($PHORUM['args'][1]) || $PHORUM['args'][1] != 'markread' ||
        !$PHORUM['DATA']['LOGGEDIN'] ||
        // These shouldn't happen
        !isset($PHORUM['forum_id']) || !isset($PHORUM['parent_id'])) return;

    // Check if the parent of the required forum_id is a second level
    // folder. In our subforums system, second level folders are used
    // for grouping a forum and its subforums.
    $parent = phorum_api_forums_get($PHORUM['parent_id']);
    if (empty($parent) ||
        $parent['forum_id'] == $parent['vroot'] ||
        $parent['parent_id'] == $parent['vroot']) return;

    // Yes, we have a second level folder.
    // Mark all forums inside the folder read. 

    // Retrieve the forums inside the folder.
    $forums = phorum_api_forums_get(NULL, $PHORUM['parent_id']);

    // Handle marking the contained forums as read.
    unset($PHORUM['user']['newinfo']);
    foreach ($forums as $forum) {
        phorum_db_newflag_allread($forum['forum_id']);
        if ($PHORUM['cache_newflags']) {
            $newflagkey = $forum['forum_id'].'-'.$PHORUM['user']['user_id'];
            phorum_cache_remove('newflags', $newflagkey);
            phorum_cache_remove('newflags_index', $newflagkey);
        }
    }

    // No further action. We'll let the regular mark read code
    // handle the redirection to a fresh index page.
}

/**
 * Handle mark read for subforums on a list page.
 */
function phorum_mod_subforums_page_list()
{
    global $PHORUM;

    // Check if we have to handle "mark read".
    if (empty($PHORUM['args'][1]) || $PHORUM['args'][1] != 'markread' ||
        empty($PHORUM['args'][2]) || !is_numeric($PHORUM['args'][2]) ||
        !$PHORUM['DATA']['LOGGEDIN'] ||
        // These shouldn't happen
        !isset($PHORUM['forum_id']) || !isset($PHORUM['parent_id'])) return;

    // setting all posts read
    unset($PHORUM['user']['newinfo']);
    phorum_db_newflag_allread();
    if ($PHORUM['cache_newflags']) {
        $newflagkey = $PHORUM['forum_id'].'-'.$PHORUM['user']['user_id'];
        phorum_cache_remove('newflags',$newflagkey);
        phorum_cache_remove('newflags_index',$newflagkey);
    }


    // Redirect to a fresh list page without markread in the url.
    $dest_url = phorum_get_url(PHORUM_LIST_URL, $PHORUM['args'][2]);
    phorum_redirect_by_url($dest_url);
    exit();
}

/**
 * Replace second level folders in the index with forum/subforum structures.
 *
 * @param array $index
 *     An array of items to show on the index page.
 *
 * @return array
 *     The possibly modified array of index page items.
 */
function phorum_mod_subforums_index($index)
{
    global $PHORUM;

    $map = array();
    $replaced = array();

    foreach ($index as $id => $item)
    {
        // Skip forums.
        if (empty($item['folder_flag'])) continue;

        // Skip the vroot folder.
        if ($item['forum_id'] == $item['vroot']) continue;

        // Skip folder if the index view is showing this folder
        // (in which case the folder is shown as a category header.)
        if ($item['forum_id'] == $PHORUM['forum_id']) continue;

        // Skip folders that have the (v)root at their parent. These
        // are the category folders.
        if ($item['parent_id'] == $item['vroot']) continue;

        // When we get here, then we are at a second level folder, for which
        // we will modify the index to show the first forum inside as the
        // main forum and the other forums inside as the subforums for that
        // forum.

        // Retrieve the forums inside the folder.
        $forums = phorum_api_forums_get(NULL, $item['forum_id']);

        // Create a replacement item for the folder item.
        unset($newitem); // prevent reusing a copy
        foreach ($forums as $forum)
        {
            // Just in case an admin put an extra level of folders in the
            // subforums grouping folder. This is not supported. We silently
            // ignore this folder.
            if ($forum['folder_flag']) continue;

            // Skip the forum if the user is not allowed to read it and
            // the hide forums feature is enabled.
            if ($PHORUM['hide_forums'] && !phorum_api_user_check_access(
                    PHORUM_USER_ALLOW_READ, $forum['forum_id'])) continue;

            // The first forum in the folder is the main forum.
            if (!isset($newitem))
            {
                $newitem = subforums_format_forum($forum);
                $newitem['subforums'] = array();
                $map[$forum['forum_id']] =& $newitem;
            }
            // The rest of the forums are subforums.
            else
            {
                unset($subforum); // prevent reusing a copy
                $subforum = subforums_format_forum($forum);
                $newitem['subforums'][] =& $subforum;
                $map[$forum['forum_id']] =& $subforum;
            }
        }

        // If one or more visible forums were found in the folder, then
        // we replace the folder with the main forum.
        if (isset($newitem)) {
            $index[$id] =& $newitem;
            $replaced[] = $id;
        }
        // No visible forums were found in the folder. We can delete the
        // complete folder from the index.
        else {
            unset($index[$id]);
        }
    }

    // Do checks for new messages.
    // In a future version of this module, we will be able to make
    // use of the newflags API calls, but currently, these are still
    // under development.
    if ($PHORUM['DATA']['LOGGEDIN'] && !empty($map))
    {
        if ($PHORUM['show_new_on_index']==2)
        {
            $new_checks = phorum_db_newflag_check(array_keys($map));

            foreach ($new_checks as $forum_id => $checks) {
                if (!empty($checks)) {
                    $map[$forum_id]['new_message_check'] = TRUE;
                } else {
                    $map[$forum_id]['new_message_check'] = FALSE;
                }
            }
        }
        elseif ($PHORUM['show_new_on_index']==1)
        {
            $new_counts = phorum_db_newflag_count(array_keys($map));

            foreach ($new_counts as $forum_id => $counts)
            {
                $map[$forum_id]['new_messages'] = number_format(
                    $counts['messages'], 0,
                    $PHORUM['dec_sep'], $PHORUM['thous_sep']
                );
                $map[$forum_id]['new_threads'] = number_format(
                    $counts['threads'], 0,
                    $PHORUM['dec_sep'], $PHORUM['thous_sep']
                );
            }
        }
    }

    // Format the subforums. These are added to the description
    // of the main forum. The formatted code is based on the module's
    // "index" template.
    foreach ($replaced as $id)
    {
        if (!empty($index[$id]['subforums']))
        {
            $PHORUM['DATA']['SUBFORUMCOUNT'] = count($index[$id]['subforums']);
            $PHORUM['DATA']['SUBFORUMS'] = $index[$id]['subforums'];
            ob_start();
            include phorum_get_template('subforums::index');
            $append = ob_get_contents();
            ob_end_clean();

            $index[$id]['description'] .= $append;
        }
    }

    return $index;
}

/**
 * Fix breadcrumbs for the subforums system.
 *
 * Second level folders should not be showns, because these are only
 * in use for grouping a forum and its subforums. This hook replaces
 * a second level folder with the main forum from that folder. 
 */
function phorum_mod_subforums_start_output()
{
    global $PHORUM;

    $seen_folder = FALSE;
    $fixed_forum_id = NULL;
    foreach ($PHORUM['DATA']['BREADCRUMBS'] as $id => $item)
    {
        if (isset($item['TYPE']) && $item['TYPE'] == 'folder')
        {
            if ($seen_folder)
            {
                // We have a second level folder. Fix this item.
                $forums = phorum_api_forums_get(NULL, $item['ID']); 
                if (!empty($forums)) {
                    $forum = array_shift($forums);
                    $PHORUM['DATA']['BREADCRUMBS'][$id] = array(
                        'URL'  => phorum_get_url(
                                      PHORUM_LIST_URL, $forum['forum_id']
                                  ),
                        'TEXT' => $forum['name'],
                        'ID'   => $forum['forum_id'],
                        'TYPE' => 'forum'
                    );
                    $fixed_forum_id = $forum['forum_id'];
                }
            } else {
                $seen_folder = TRUE;
            }
            continue;
        } else {
            $seen_folder = FALSE;
        }

        // When we are in a main forum, then the breadcrumbs look
        // like Home -> Category -> Forum X -> Forum X
        // after fixing a folder item. Here we filter out the
        // last "Forum X".
        if ($fixed_forum_id !== NULL &&
            isset($item['TYPE']) && $item['TYPE'] == 'forum' &&
            $item['ID'] == $fixed_forum_id) {
            unset($PHORUM['DATA']['BREADCRUMBS'][$id]);
        }
    }
}

/**
 * Display subforums for a forum on the message list page.
 */
function phorum_mod_subforums_after_header()
{
    global $PHORUM;

    // We only run this hook on the list page.
    if (phorum_page != 'list') return;

    // Check if the parent of the current forum_id is a second level
    // folder. In our subforums system, second level folders are used
    // for grouping a forum and its subforums.
    $parent = phorum_api_forums_get($PHORUM['parent_id']);
    if (empty($parent) ||
        $parent['forum_id'] == $parent['vroot'] ||
        $parent['parent_id'] == $parent['vroot']) return;

    // Yes, we have a second level folder.
    // Retrieve the forums inside the folder.
    $forums = phorum_api_forums_get(NULL, $PHORUM['parent_id']);

    // If the first forum in the list is the current forum, then we
    // are in the main forum. In other cases, we are in a subforum, where
    // we do not want to show the forum list.
    // (as for the crazy construction: array_shift() would be nice, but
    // that one would reset the array indices, breaking the code below.)
    reset($forums);
    list ($id, $forum) = each($forums);
    if ($forum['forum_id'] != $PHORUM['forum_id']) return;
    unset($forums[$forum['forum_id']]);
    reset($forums);

    // If we have no forums left by now, then we are done.
    if (empty($forums)) return;

    // Do checks for new messages.
    // In a future version of this module, we will be able to make
    // use of the newflags API calls, but currently, these are still
    // under development.
    if ($PHORUM['DATA']['LOGGEDIN'])
    {
        if ($PHORUM['show_new_on_index']==2)
        {
            $new_checks = phorum_db_newflag_check(array_keys($forums));

            foreach ($new_checks as $forum_id => $checks) {
                if (!empty($checks)) {
                    $forums[$forum_id]['new_message_check'] = TRUE;
                } else {
                    $forums[$forum_id]['new_message_check'] = FALSE;
                }
            }
        }
        elseif ($PHORUM['show_new_on_index']==1)
        {
            $new_counts = phorum_db_newflag_count(array_keys($forums));

            foreach ($new_counts as $forum_id => $counts)
            {
                $forums[$forum_id]['new_messages'] = number_format(
                    $counts['messages'], 0,
                    $PHORUM['dec_sep'], $PHORUM['thous_sep']
                );
                $forums[$forum_id]['new_threads'] = number_format(
                    $counts['threads'], 0,
                    $PHORUM['dec_sep'], $PHORUM['thous_sep']
                );
            }
        }
    }

    // Format the forums.
    foreach ($forums as $id => $forum) {
        $forums[$id] = subforums_format_forum($forum);
    }

    // Add a header item.
    array_unshift($forums, array(
        'folder_flag' => TRUE,
        'name' => count($forums) == 1
                ? $PHORUM['DATA']['LANG']['mod_subforums']['subforum']
                : $PHORUM['DATA']['LANG']['mod_subforums']['subforums'],
        'level' => 0,
        'URL' => array(
            'LIST' => phorum_get_url(
                 PHORUM_LIST_URL, $PHORUM['forum_id']
            )
        )
    ));

    // Render the forum list.
    $PHORUM['DATA']['FORUMS'] = $forums;
    include phorum_get_template('subforums::list');
}

/**
 * This function is used for template formatting the data for a single
 * forum. In a future version, we can replace this with the API layer
 * call phorum_api_format_forum(). For now, we cannot yet use that function,
 * since it is only available in the Phorum development tree.
 *
 * @param array $forum
 *     An array of forum data.
 *
 * @return array
 *     The formatted forum data.
 */
function subforums_format_forum($forum)
{
    global $PHORUM;

    $forum['URL']['LIST'] = phorum_get_url(PHORUM_LIST_URL, $forum['forum_id']);

    if ($PHORUM['DATA']['LOGGEDIN']) {
        if (phorum_page == 'list') {
            $forum['URL']['MARK_READ'] = phorum_get_url(
                PHORUM_LIST_URL, $forum['forum_id'],
                'markread', $PHORUM['forum_id']
            );
        } else {
            $forum['URL']['MARK_READ'] = phorum_get_url(
                PHORUM_INDEX_URL, $forum['forum_id'],
                'markread', $PHORUM['forum_id']
            );
        }
    }

    if (!empty($PHORUM['use_rss'])) {
        $forum['URL']['FEED'] = phorum_get_url(
            PHORUM_FEED_URL, $forum['forum_id'],
            'type='.$PHORUM['default_feed']
        );
    }

    if ($forum['message_count'] > 0) {
        $forum['last_post'] = phorum_date(
            $PHORUM['long_date_time'], $forum['last_post_time']
        );
        $forum['raw_last_post'] = $forum['last_post_time'];
    } else {
        $forum['last_post'] = '&nbsp;';
    }

    $forum['raw_message_count'] = $forum['message_count'];
    $forum['message_count'] = number_format(
        $forum['message_count'], 0,
        $PHORUM['dec_sep'], $PHORUM['thous_sep']
    );

    $forum['raw_thread_count'] = $forum['thread_count'];
    $forum['thread_count'] = number_format(
        $forum['thread_count'], 0,
        $PHORUM['dec_sep'], $PHORUM['thous_sep']
    );

    return $forum;
}

?>
