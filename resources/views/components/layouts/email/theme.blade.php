<style>
    /* Base */
    body,
    body *:not(html):not(style):not(br):not(tr):not(code) {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        margin: 0;
        padding: 0;
        background: #f3f3f3;
        color: #20242b;
        font-family: Arial, Helvetica, sans-serif;
    }

    a {
        text-decoration: none;
    }

    a img {
        border: 0;
    }

    /* Layout shell */
    .email-preheader {
        display: none;
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        color: transparent;
        line-height: 1px;
    }

    .email-bg {
        background: #f3f3f3;
        margin: 0;
        padding: 0 14px;
    }

    .email-shell {
        max-width: 800px;
        background: #ffffff;
    }

    .email-head {
        padding: 48px 47px 0;
        background: #ffffff;
    }

    .email-brand {
        display: inline-block;
        color: #344054;
        text-decoration: none;
        font-size: 24px;
        font-weight: 700;
    }

    .email-brand-cell {
        vertical-align: middle;
    }

    .email-logo {
        display: block;
        max-width: 190px;
        height: 42px;
        object-fit: contain;
        border: 0;
    }

    .email-title {
        margin: 58px 0 28px;
        color: #20242b;
        font-size: 26px;
        line-height: 1.25;
        font-weight: 800;
    }

    .email-rule {
        border-top: 2px solid #d2d6dc;
        font-size: 0;
        line-height: 0;
    }

    .email-content {
        padding: 26px 47px 70px;
        font-size: 14px;
        line-height: 1.75;
        color: #7a8491;
    }

    /* Footer */
    .email-footer {
        padding: 42px 34px 44px;
        background: #fafafa;
        color: #7a8491;
        font-size: 12px;
        line-height: 1.6;
    }

    .email-footer-name {
        margin: 0 0 20px;
        color: #20242b;
        font-size: 13px;
        font-weight: 700;
    }

    .email-footer-text {
        margin: 0 0 16px;
        max-width: 340px;
    }

    .email-footer-link {
        color: #2563eb;
        font-weight: 700;
        text-decoration: underline;
    }

    .email-footer-copy {
        margin: 0;
        color: #98a2b3;
    }

    /* Content typography */
    .para {
        margin: 0 0 18px;
    }

    .para-lg {
        margin: 0 0 22px;
    }

    .para-flush {
        margin: 0;
    }

    .text-soft {
        color: #536247;
    }

    /* OTP block */
    .otp-box {
        margin: 28px 0;
        padding: 22px;
        border-radius: 18px;
        background: #eff5d6;
        border: 1px solid #d5e4a2;
        text-align: center;
    }

    .otp-label {
        color: #536247;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1.4px;
    }

    .otp-code {
        margin-top: 8px;
        color: #1d2b14;
        font-size: 38px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: 8px;
        font-family: Verdana, Geneva, sans-serif;
    }

    .otp-expiry {
        margin-top: 12px;
        color: #536247;
        font-size: 13px;
    }

    /* Buttons */
    .btn-row {
        margin: 28px 0;
    }

    .btn-row-flush {
        margin: 28px 0 0;
    }

    .btn-cell {
        border-radius: 999px;
    }

    .btn-cell-lime {
        background: #8fb339;
    }

    .btn-cell-forest {
        background: #24311d;
    }

    .btn {
        display: inline-block;
        padding: 13px 24px;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
        border-radius: 999px;
    }

    .btn-dark {
        color: #18230f;
    }

    .btn-light {
        color: #fffdf2;
    }

    /* Info panel */
    .info-panel {
        margin: 0 0 24px;
        border: 1px solid #e3dcc8;
        border-radius: 16px;
        background: #fbf8ee;
    }

    .info-label {
        padding: 18px 20px;
        color: #536247;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 700;
    }

    .info-value {
        padding: 18px 20px;
        color: #1f2b18;
        font-size: 14px;
        font-weight: 700;
    }

    .info-label-last {
        padding: 0 20px 18px;
        color: #536247;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 700;
    }

    .info-value-last {
        padding: 0 20px 18px;
        color: #1f2b18;
        font-size: 14px;
        font-weight: 700;
    }
</style>
