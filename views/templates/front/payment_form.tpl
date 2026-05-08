<form action="{$action_url|escape:'html':'UTF-8'}" method="post" id="paynetworx-form" class="paynetworx-form" novalidate>
    <input type="hidden" name="paynetworx_nonce" value="{$paynetworx_nonce|escape:'html':'UTF-8'}">

    <div class="paynetworx-field">
        <label class="paynetworx-label" for="card_number">{l s='Card Number' mod='paynetworx'}</label>
        <div class="paynetworx-input-wrapper">
            <input type="tel" name="card_number" id="card_number"
                   class="paynetworx-input"
                   placeholder="0000 0000 0000 0000"
                   maxlength="23"
                   autocomplete="cc-number"
                   inputmode="numeric"
                   required>
            <span id="pnx-brand" class="paynetworx-brand-badge"></span>
        </div>
        <small class="paynetworx-error" id="err-number"></small>
    </div>

    <div class="paynetworx-row">
        <div class="paynetworx-col">
            <div class="paynetworx-field">
                <label class="paynetworx-label" for="card_expiry">{l s='Expiry' mod='paynetworx'}</label>
                <div class="paynetworx-input-wrapper">
                    <input type="text" id="card_expiry"
                           class="paynetworx-input"
                           placeholder="MM / YY"
                           maxlength="7"
                           autocomplete="cc-exp"
                           required>
                    <input type="hidden" name="card_expiry_month" id="card_expiry_month">
                    <input type="hidden" name="card_expiry_year"  id="card_expiry_year">
                </div>
                <small class="paynetworx-error" id="err-expiry"></small>
            </div>
        </div>
        <div class="paynetworx-col">
            <div class="paynetworx-field">
                <label class="paynetworx-label" for="card_cvc">{l s='CVC' mod='paynetworx'}</label>
                <div class="paynetworx-input-wrapper">
                    <input type="tel" name="card_cvc" id="card_cvc"
                           class="paynetworx-input"
                           placeholder="123"
                           maxlength="4"
                           autocomplete="cc-csc"
                           inputmode="numeric"
                           required>
                </div>
                <small class="paynetworx-error" id="err-cvc"></small>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';

    var form      = document.getElementById('paynetworx-form');
    var numInput  = document.getElementById('card_number');
    var expInput  = document.getElementById('card_expiry');
    var hiddenMM  = document.getElementById('card_expiry_month');
    var hiddenYY  = document.getElementById('card_expiry_year');
    var cvcInput  = document.getElementById('card_cvc');
    var brandBadge = document.getElementById('pnx-brand');

    var errNum    = document.getElementById('err-number');
    var errExp    = document.getElementById('err-expiry');
    var errCvc    = document.getElementById('err-cvc');

    var brand = 'unknown';

    // --- Brand detection ---
    var BRANDS = [
        { name: 'amex',       label: 'Amex',       re: /^3[47]/,                       cvcLen: 4 },
        { name: 'visa',       label: 'Visa',       re: /^4/,                            cvcLen: 3 },
        { name: 'mastercard', label: 'Mastercard', re: /^(5[1-5]|2[2-7])/,             cvcLen: 3 },
        { name: 'discover',   label: 'Discover',   re: /^(6011|65)/,                   cvcLen: 3 },
        { name: 'diners',     label: 'Diners',     re: /^3[0689]/,                     cvcLen: 3 },
        { name: 'jcb',        label: 'JCB',        re: /^(2131|1800|35)/,              cvcLen: 3 },
    ];

    function detectBrand(digits) {
        for (var i = 0; i < BRANDS.length; i++) {
            if (BRANDS[i].re.test(digits)) return BRANDS[i];
        }
        return null;
    }

    function updateBadge(detected) {
        if (detected) {
            brand = detected.name;
            brandBadge.textContent  = detected.label;
            brandBadge.className    = 'paynetworx-brand-badge active ' + detected.name;
            cvcInput.maxLength      = detected.cvcLen;
            cvcInput.placeholder    = detected.cvcLen === 4 ? '1234' : '123';
        } else {
            brand = 'unknown';
            brandBadge.textContent = '';
            brandBadge.className   = 'paynetworx-brand-badge';
            cvcInput.maxLength     = 4;
            cvcInput.placeholder   = '123';
        }
    }

    // --- Card number formatting ---
    numInput.addEventListener('input', function () {
        var digits = numInput.value.replace(/\D/g, '').substring(0, 19);
        var formatted = digits.replace(/(.{4})/g, '$1 ').trim();
        numInput.value = formatted;
        updateBadge(detectBrand(digits));
        clearError(numInput, errNum);
    });

    // --- Expiry formatting ---
    expInput.addEventListener('input', function () {
        var digits = expInput.value.replace(/\D/g, '').substring(0, 4);
        expInput.value = digits.length >= 3
            ? digits.substring(0, 2) + ' / ' + digits.substring(2)
            : digits;
        hiddenMM.value = digits.substring(0, 2);
        hiddenYY.value = digits.substring(2, 4);
        clearError(expInput, errExp);
    });

    cvcInput.addEventListener('input', function () {
        clearError(cvcInput, errCvc);
    });

    // --- Helpers ---
    function showError(input, el, msg) {
        el.textContent = msg;
        el.style.display = 'block';
        input.classList.add('has-error');
    }

    function clearError(input, el) {
        el.textContent = '';
        el.style.display = 'none';
        input.classList.remove('has-error');
    }

    function luhn(n) {
        var s = 0, e = false;
        for (var i = n.length - 1; i >= 0; i--) {
            var d = parseInt(n[i], 10);
            if (e && (d *= 2) > 9) d -= 9;
            s += d;
            e = !e;
        }
        return s % 10 === 0;
    }

    // --- Validation ---
    function validate() {
        var ok = true;
        var digits = numInput.value.replace(/\D/g, '');

        if (digits.length < 13 || digits.length > 19 || !luhn(digits)) {
            showError(numInput, errNum, '{l s="Invalid card number." mod="paynetworx" js=1}');
            ok = false;
        }

        var mm = parseInt(hiddenMM.value, 10) || 0;
        var yy = parseInt(hiddenYY.value, 10) || 0;
        var now = new Date();
        var cy  = now.getFullYear() % 100;
        var cm  = now.getMonth() + 1;

        if (!mm || !yy || mm < 1 || mm > 12) {
            showError(expInput, errExp, '{l s="Invalid expiry date." mod="paynetworx" js=1}');
            ok = false;
        } else if (yy < cy || (yy === cy && mm < cm)) {
            showError(expInput, errExp, '{l s="Card has expired." mod="paynetworx" js=1}');
            ok = false;
        }

        var cvcDigits = cvcInput.value.replace(/\D/g, '');
        var reqLen    = (brand === 'amex') ? 4 : 3;
        if (cvcDigits.length !== reqLen) {
            showError(cvcInput, errCvc, '{l s="Enter a valid CVC." mod="paynetworx" js=1}');
            ok = false;
        }

        return ok;
    }

    // --- Form submit ---
    form.addEventListener('submit', function (e) {
        // Sync hidden fields one final time
        var d = expInput.value.replace(/\D/g, '');
        hiddenMM.value = d.substring(0, 2);
        hiddenYY.value = d.substring(2, 4);

        if (!validate()) {
            e.preventDefault();
            e.stopPropagation();
        }
    });
}());
</script>
