{IF NOT SUBFORUMCOUNT 0}
<div style="margin:1em 0 1em 0">
  <p>
    <b>
      {IF SUBFORUMCOUNT 1}
        {LANG->mod_subforums->subforum}:
      {ELSE}
        {LANG->mod_subforums->subforums}:
      {/IF}
    </b>
    {VAR FIRST TRUE}
    {LOOP SUBFORUMS}{IF NOT FIRST}, {/IF}{VAR FIRST FALSE}<span style="white-space:nowrap"><a href="{SUBFORUMS->URL->LIST}">{SUBFORUMS->name}</a>{IF SUBFORUMS->new_messages} (<span class="new-flag">{SUBFORUMS->new_messages} {LANG->newflag}</span>){/IF}{IF SUBFORUMS->new_message_check} <span class="new-flag">({LANG->NewMessages})</span>{/IF}</span>{/LOOP SUBFORUMS}
  </p>
</div>
{/IF}
