{extends file='page.tpl'}

{block name="page_content"}
  <h1>{l s='Newsletter Unsubscription' d='Modules.Emailsubscription.Shop'}</h1>

<div class="email_subscription block_newsletter_unsubscribe" id="blockEmailSubscription_unsubscribe">
  {if $variables.msg}
    <p class="notification {if $variables.nw_error}notification-error{else}notification-success{/if}">{$variables.msg}</p>
  {/if}
  <form action="{$urls.current_url}" method="post">
    <input type="text" name="email" value="{$variables.value}" placeholder="{l s='Your e-mail' d='Modules.Emailsubscription.Shop'}" />
    {if $variables.conditions}
      <p>{$variables.conditions nofilter}</p>
    {/if}
    {hook h='displayNewsletterRegistration'}
    <input type="hidden" value="blockEmailSubscription_unsubscribe" name="blockHookName" />
    <input type="submit" value="ok" name="submitNewsletter" />
    <input type="hidden" name="action" value="1" />
  </form>
</div>

{/block}