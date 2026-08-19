# Private Captcha Magento 2 Plugin

![CI](https://github.com/PrivateCaptcha/private-captcha-magento2/actions/workflows/ci.yml/badge.svg)

## Features

- **Form Protection**: Most standard StoreFront forms (see [below](#supported-forms))
- **Flexible Configuration**: Theme, language, start mode, and custom styling options per Website and Store
- **EU Compliance**: Support for EU-only endpoints and custom domains

## Installation

> <mark>Check detailed step-by-step setup instructions [here](https://docs.privatecaptcha.com/docs/integrations/magento2/).</mark>

1. Install the published package in a Magento project:
    ```bash
    composer require private-captcha/magento2
    bin/magento module:enable PrivateCaptcha_PrivateCaptcha
    bin/magento setup:upgrade
    bin/magento setup:di:compile
    bin/magento setup:static-content:deploy -f en_US
    bin/magento cache:flush
    ```
2. Go to **Stores → Configuration → Security → Private Captcha**
3. Add your **API Key** and **Site Key** from [Private Captcha Portal](https://portal.privatecaptcha.com)
4. Enable desired form integrations

## Supported Forms

- Customer Login
- Registration
- Forgot Password
- Contact
- Product Review
- Send to Friend
- Wishlist Share
- Orders & Returns

## Requirements

- Magento / Adobe Commerce 2.4.x
- PHP 8.1+
- [Private Captcha account](https://portal.privatecaptcha.com/signup)

## License

MIT License

