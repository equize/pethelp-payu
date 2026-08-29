<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( sprintf( __( 'Zmiana karty – %s', 'pethelp-payu-cards' ), $shop_name ) ); ?></title>
<style>
:root {
    --cc-accent:       #0062d6;
    --cc-accent-hover: #004db0;
    --cc-page-bg:      #f0f2f5;
    --cc-card-bg:      #fff;
    --cc-card-radius:  12px;
    --cc-card-shadow:  0 4px 24px rgba(0, 0, 0, .10);
    --cc-font:         -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --cc-text:         #1a1a1a;
    --cc-text-muted:   #555;
    --cc-border:       #e2e2e2;
}
*, *::before, *::after { box-sizing: border-box; }
body {
    margin: 0;
    font-family: var(--cc-font);
    background: var(--cc-page-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    color: var(--cc-text);
    padding: 24px 0;
}
.cc-card {
    background: var(--cc-card-bg);
    border-radius: var(--cc-card-radius);
    padding: 40px;
    max-width: 480px;
    width: 92%;
    box-shadow: var(--cc-card-shadow);
}
.cc-logo { text-align: center; margin-bottom: 24px; }
.cc-logo img { height: 44px; border-radius: 8px; }
.cc-title { font-size: 1.2em; font-weight: 700; margin-bottom: 4px; text-align: center; }
.cc-sub { font-size: .88em; color: var(--cc-text-muted); text-align: center; margin-bottom: 24px; }

.cc-current { background: #f8f9fc; border-radius: 8px; padding: 14px 16px; margin-bottom: 24px; font-size: .9em; }
.cc-current strong { display: block; margin-bottom: 2px; }
.cc-current .cc-invalid { color: #c00; }

.cc-option { display: grid; grid-template-columns: 25px 1fr; border: 1px solid var(--cc-border); border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; cursor: pointer; }
.cc-option:hover { border-color: var(--cc-accent); }
.cc-option .cc-masked { font-weight: 600; }
.cc-option .cc-meta { color: var(--cc-text-muted); font-size: .75em; margin-top: 4px; }

.cc-actions { display: flex; gap: 12px; margin-top: 24px; }
.cc-btn { flex: 1; text-align: center; padding: 12px 20px; border-radius: 6px; font-size: .95em; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
.cc-btn-primary { background: var(--cc-accent); color: #fff; }
.cc-btn-primary:hover { background: var(--cc-accent-hover); }
.cc-btn-secondary { background: #eee; color: var(--cc-text); }
.cc-btn-secondary:hover { background: #e0e0e0; }

.cc-confirm { text-align: center; }
.cc-confirm-icon { font-size: 2.5em; color: #1a9c53; margin-bottom: 12px; }

.cc-error { background: #fdecea; color: #c00; border-radius: 8px; padding: 10px 14px; font-size: .85em; margin-bottom: 16px; }
.cc-empty { font-size: .88em; color: var(--cc-text-muted); margin-bottom: 16px; }
</style>
</head>
<body>
<div class="cc-card">

    <?php if ( $logo_url ) : ?>
    <div class="cc-logo"><img src="<?php echo $logo_url; ?>" alt="<?php echo $shop_name; ?>"></div>
    <?php endif; ?>

    <?php if ( $done ) : ?>

        <div class="cc-confirm">
            <div class="cc-confirm-icon">✓</div>
            <div class="cc-title"><?php esc_html_e( 'Karta została zmieniona', 'pethelp-payu-cards' ); ?></div>
            <p class="cc-sub"><?php esc_html_e( 'Kolejne płatności subskrypcji będą pobierane z nowo wybranej karty.', 'pethelp-payu-cards' ); ?></p>
            <a class="cc-btn cc-btn-primary" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Wróć do strony głównej', 'pethelp-payu-cards' ); ?></a>
        </div>

    <?php else : ?>

        <div class="cc-title"><?php esc_html_e( 'Zmień kartę', 'pethelp-payu-cards' ); ?></div>
        <p class="cc-sub">
            <?php
            printf(
                /* translators: subscription number */
                esc_html__( 'Subskrypcja #%s', 'pethelp-payu-cards' ),
                esc_html( $subscription->get_order_number() )
            );
            ?>
        </p>

        <div class="cc-current">
            <strong><?php esc_html_e( 'Aktualnie przypisana karta', 'pethelp-payu-cards' ); ?></strong>
            <?php if ( $current_token ) : ?>
                <?php echo esc_html( $current_token['masked_card'] ?: '••••' ); ?>
                <?php if ( ! empty( $current_token['card_brand'] ) ) : ?>
                    (<?php echo esc_html( $current_token['card_brand'] ); ?>)
                <?php endif; ?>
                <?php if ( $current_token['status'] !== 'active' ) : ?>
                    <span class="cc-invalid">– <?php echo esc_html( $current_token['status'] === 'invalidated' ? __( 'unieważniona', 'pethelp-payu-cards' ) : __( 'wygasła', 'pethelp-payu-cards' ) ); ?></span>
                <?php endif; ?>
            <?php else : ?>
                <?php esc_html_e( 'Brak przypisanej karty.', 'pethelp-payu-cards' ); ?>
            <?php endif; ?>
        </div>

        <div id="cc-error" class="cc-error" style="<?php echo $error ? '' : 'display:none;'; ?>"><?php echo esc_html( $error ); ?></div>

        <form method="post" id="cc-form" action="<?php echo esc_url( Pethelp_PayU_Card_Change_Page::get_url( $subscription->get_id(), $redirect ?? null ) ); ?>">
            <input type="hidden" name="pethelp-payu-change-card" value="1" />
            <input type="hidden" name="subscription_id" value="<?php echo (int) $subscription->get_id(); ?>" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'pethelp_payu_change_card_' . $subscription->get_id() ) ); ?>" />
            <input type="hidden" name="pethelp_payu_change_card_action" id="cc-action" value=""/>

            <?php if ( ! empty( $tokens ) ) : ?>
                <?php foreach ( $tokens as $token ) : ?>
                    <label class="cc-option">
                        <input type="radio" name="cc_choice" value="existing:<?php echo (int) $token['id']; ?>" />
                        <div>
                            <span class="cc-masked">
                                <?php echo esc_html( $token['masked_card'] ?: '••••' ); ?>
                            </span>
                            <div class="cc-meta">
                                <?php if ( ! empty( $current_token['card_brand'] ) ) : ?>
                                    <?php echo esc_html( $current_token['card_brand'] ); ?> 
                                    <?php if ( $token['exp_month'] && $token['exp_year'] ) : ?>
                                        -
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ( $token['exp_month'] && $token['exp_year'] ) : ?>
                                    Data ważności:
                                    <?php echo esc_html( sprintf( '%02d/%d', $token['exp_month'], $token['exp_year'] ) ); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </label>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="cc-empty"><?php esc_html_e( 'Nie masz innych zapisanych, aktywnych kart.', 'pethelp-payu-cards' ); ?></p>
            <?php endif; ?>

            <label class="cc-option">
                <input type="radio" name="cc_choice" value="new" />
                <span class="cc-masked"><?php esc_html_e( 'Dodaj nową kartę', 'pethelp-payu-cards' ); ?></span>
            </label>

            <input type="hidden" name="pethelp_payu_token_type" id="cc-token-type" value="" />
            <input type="hidden" name="pethelp_payu_token_value" id="cc-token-value" value="" />
            <input type="hidden" name="pethelp_payu_masked_card" id="cc-masked-card" value="" />
            <input type="hidden" name="pethelp_payu_widget_response" id="cc-widget-response" value="" />

            <?php if ( $widget ) : ?>
                <button type="button" style="display:none;" id="cc-widget-trigger"></button>
                <script
                    src="<?php echo esc_url( $widget['widget_url'] ); ?>"
                    pay-button="#cc-widget-trigger"
                    merchant-pos-id="<?php echo esc_attr( $widget['merchant_pos_id'] ); ?>"
                    shop-name="<?php echo esc_attr( $widget['shop_name'] ); ?>"
                    total-amount="<?php echo esc_attr( $widget['total_amount'] ); ?>"
                    currency-code="<?php echo esc_attr( $widget['currency_code'] ); ?>"
                    customer-language="<?php echo esc_attr( $widget['customer_language'] ); ?>"
                    store-card="<?php echo esc_attr( $widget['store_card'] ); ?>"
                    recurring-payment="<?php echo esc_attr( $widget['recurring_payment'] ); ?>"
                    customer-email="<?php echo esc_attr( $widget['customer_email'] ); ?>"
                    sig="<?php echo esc_attr( $widget['sig'] ); ?>"
                    success-callback="pethelpPayuCardChangeCallback"
                ></script>
            <?php endif; ?>

            <div class="cc-actions">
                <a class="cc-btn cc-btn-secondary" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Anuluj', 'pethelp-payu-cards' ); ?></a>
                <button type="submit" class="cc-btn cc-btn-primary" id="cc-submit"><?php esc_html_e( 'Zapisz zmianę', 'pethelp-payu-cards' ); ?></button>
            </div>
        </form>

        <script>
        (function () {
            var form        = document.getElementById('cc-form');
            var actionInput = document.getElementById('cc-action');
            var errorBox    = document.getElementById('cc-error');
            var tokenValue  = document.getElementById('cc-token-value');
            var haveNewCardToken = false;

            window.pethelpPayuCardChangeCallback = function (response) {
                response = response || {};
                document.getElementById('cc-token-type').value      = response.tokenType || '';
                document.getElementById('cc-token-value').value     = response.value || '';
                document.getElementById('cc-masked-card').value     = response.maskedCard || '';
                document.getElementById('cc-widget-response').value = JSON.stringify(response);
                haveNewCardToken = true;
                actionInput.value = 'new';
                form.submit();
            };

            function showError(msg) {
                errorBox.textContent = msg;
                errorBox.style.display = 'block';
            }

            form.addEventListener('submit', function (e) {
                var choice = form.querySelector('input[name="cc_choice"]:checked');

                if (!choice) {
                    e.preventDefault();
                    showError(<?php echo wp_json_encode( __( 'Wybierz kartę lub dodaj nową.', 'pethelp-payu-cards' ) ); ?>);
                    return;
                }

                if (choice.value === 'new') {
                    if (!haveNewCardToken || !tokenValue.value) {
                        e.preventDefault();
                        var trigger = document.getElementById('cc-widget-trigger');
                        if (trigger) {
                            trigger.click();
                        } else {
                            showError(<?php echo wp_json_encode( __( 'Dodawanie nowej karty jest chwilowo niedostępne.', 'pethelp-payu-cards' ) ); ?>);
                        }
                        return;
                    }
                    actionInput.value = 'new';
                } else {
                    actionInput.value = 'existing';
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'token_id';
                    hidden.value = choice.value.split(':')[1];
                    form.appendChild(hidden);
                }
            });
        }());
        </script>

    <?php endif; ?>

</div>
</body>
</html>
