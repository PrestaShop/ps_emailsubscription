{*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

<div class="email_subscription">
  <h4>{l s='Newsletter' mod='ps_emailsubscription'}</h4>
  {if $msg}
    <p class="notification {if $nw_error}notification-error{else}notification-success{/if}">{$msg}</p>
  {/if}
  <form action="{$urls.pages.index}" method="post">
    <input type="text" name="email" value="{$value}" placeholder="{l s='Your e-mail' mod='ps_emailsubscription'}" />
    {if $need_confirmation}
    <span class="custom-checkbox">
       <input type="checkbox" name="confirm-optin" value="1" required>
       <label>{l s='I agree to receive newsletter emails and I am aware of [1]the privacy policy[/1]' tags=['<a href="%s">'|sprintf:$link->getCMSLink($cms_page)] mod='ps_emailsubscription'}</label>
    </span>
    {/if}
    <input type="submit" value="ok" name="submitNewsletter" />
    <input type="hidden" name="action" value="0" />
  </form>
</div>
