{if $order_reference}
<div class="paynetworx-confirmation">
    <p>{l s='Thank you! Your payment was processed successfully.' mod='paynetworx'}</p>
    <p>{l s='Order reference:' mod='paynetworx'} <strong>{$order_reference|escape:'html':'UTF-8'}</strong></p>
    <p>{l s='A confirmation email has been sent to you.' mod='paynetworx'}</p>
</div>
{else}
<div class="paynetworx-confirmation">
    <p>{l s='Thank you! Your payment was processed successfully.' mod='paynetworx'}</p>
</div>
{/if}
