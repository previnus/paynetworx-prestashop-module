<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Paynetworx extends PaymentModule
{
    public function __construct()
    {
        $this->name    = 'paynetworx';
        $this->tab     = 'payments_gateways';
        $this->version = '1.1.0';
        $this->author  = 'ArcPro Media Inc.';
        $this->author_uri = 'https://www.arcpromedia.com';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '9.99.99',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Paynetworx');
        $this->description = $this->l('Accept credit card payments via Paynetworx.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? Saved credentials will be deleted.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->installDb()
            && Configuration::updateValue('PAYNETWORX_MODE', 'test')
            && Configuration::updateValue('PAYNETWORX_TOKEN_USER', '')
            && Configuration::updateValue('PAYNETWORX_TOKEN_PASS', '');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('PAYNETWORX_TOKEN_USER')
            && Configuration::deleteByName('PAYNETWORX_TOKEN_PASS')
            && Configuration::deleteByName('PAYNETWORX_MODE')
            && Db::getInstance()->execute(
                'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paynetworx_transactions`'
            );
    }

    private function installDb()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paynetworx_transactions` (
                `id_transaction` INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `id_cart`        INT UNSIGNED     NOT NULL,
                `id_order`       INT UNSIGNED     NOT NULL DEFAULT 0,
                `transaction_id` VARCHAR(64)      NOT NULL DEFAULT \'\',
                `auth_code`      VARCHAR(32)      NOT NULL DEFAULT \'\',
                `amount`         DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
                `currency`       VARCHAR(3)       NOT NULL DEFAULT \'\',
                `status`         VARCHAR(16)      NOT NULL DEFAULT \'pending\',
                `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_transaction`),
                UNIQUE KEY `uq_cart` (`id_cart`),
                KEY `idx_order` (`id_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
    }

    public function isConfigured()
    {
        return Configuration::get('PAYNETWORX_TOKEN_USER')
            && Configuration::get('PAYNETWORX_TOKEN_PASS');
    }

    public function hookHeader()
    {
        // Only load on checkout pages
        $page = isset($this->context->controller->php_self) ? $this->context->controller->php_self : '';
        if (!in_array($page, ['order', 'order-opc', 'checkout'], true)) {
            return;
        }
        $this->context->controller->addCSS($this->_path . 'views/css/paynetworx.css', 'all');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPaynetworxConfig')) {
            $user = trim(Tools::getValue('PAYNETWORX_TOKEN_USER'));
            $pass = trim(Tools::getValue('PAYNETWORX_TOKEN_PASS'));
            $mode = Tools::getValue('PAYNETWORX_MODE') === 'live' ? 'live' : 'test';

            if (empty($user) || empty($pass)) {
                $output .= $this->displayError($this->l('Access Token User and Password are required.'));
            } else {
                Configuration::updateValue('PAYNETWORX_TOKEN_USER', $user);
                Configuration::updateValue('PAYNETWORX_TOKEN_PASS', $pass);
                Configuration::updateValue('PAYNETWORX_MODE', $mode);
                $output .= $this->displayConfirmation($this->l('Settings saved successfully.'));
            }
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table  = $this->table;
        $helper->module = $this;
        $helper->default_form_language    = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier   = $this->identifier;
        $helper->submit_action = 'submitPaynetworxConfig';
        $helper->currentIndex  = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $mode = Configuration::get('PAYNETWORX_MODE', 'test');

        $helper->tpl_vars = [
            'fields_value' => [
                'PAYNETWORX_TOKEN_USER' => Configuration::get('PAYNETWORX_TOKEN_USER'),
                'PAYNETWORX_TOKEN_PASS' => '',
                'PAYNETWORX_MODE'       => $mode,
            ],
            'languages'   => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $fields = [
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('API Credentials'),
                        'icon'  => 'icon-key',
                    ],
                    'description' => $this->l('Enter the Access Token credentials provided by Paynetworx during merchant onboarding.'),
                    'input' => [
                        [
                            'type'     => 'select',
                            'label'    => $this->l('Environment'),
                            'name'     => 'PAYNETWORX_MODE',
                            'required' => true,
                            'options'  => [
                                'query' => [
                                    ['id' => 'test', 'name' => $this->l('Test / Sandbox')],
                                    ['id' => 'live', 'name' => $this->l('Live / Production')],
                                ],
                                'id'   => 'id',
                                'name' => 'name',
                            ],
                        ],
                        [
                            'type'     => 'text',
                            'label'    => $this->l('Access Token User'),
                            'name'     => 'PAYNETWORX_TOKEN_USER',
                            'required' => true,
                            'desc'     => $this->l('Provided by Paynetworx during onboarding.'),
                        ],
                        [
                            'type'     => 'password',
                            'label'    => $this->l('Access Token Password'),
                            'name'     => 'PAYNETWORX_TOKEN_PASS',
                            'required' => true,
                            'desc'     => $this->l('Leave blank to keep the current password unchanged.'),
                        ],
                    ],
                    'submit' => [
                        'title' => $this->l('Save'),
                    ],
                ],
            ],
        ];

        return $helper->generateForm($fields);
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->isConfigured()) {
            return [];
        }

        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText($this->l('Pay by Credit / Debit Card'))
               ->setForm($this->generatePaymentForm());

        return [$option];
    }

    protected function generatePaymentForm()
    {
        $nonce = bin2hex(random_bytes(16));
        $this->context->cookie->paynetworx_nonce = $nonce;

        $this->context->smarty->assign([
            'action_url'       => $this->context->link->getModuleLink($this->name, 'validation', [], true),
            'paynetworx_nonce' => $nonce,
        ]);

        return $this->display(__FILE__, 'views/templates/front/payment_form.tpl');
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $order = isset($params['order']) ? $params['order'] : null;

        $this->context->smarty->assign([
            'order_reference' => $order ? $order->reference : '',
        ]);

        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }
}
