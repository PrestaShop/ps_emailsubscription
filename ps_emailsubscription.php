<?php
/*
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
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Foundation\Database\EntityManager;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Ps_Emailsubscription extends Module implements WidgetInterface
{
    const GUEST_NOT_REGISTERED = -1;
    const CUSTOMER_NOT_REGISTERED = 0;
    const GUEST_REGISTERED = 1;
    const CUSTOMER_REGISTERED = 2;

    const LEGAL_PRIVACY = 'LEGAL_PRIVACY';

    public function __construct(EntityManager $entity_manager)
    {
        $this->name = 'ps_emailsubscription';
        $this->need_instance = 0;

        $this->controllers = array('verification');

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('E-mail subscription form');
        $this->description = $this->l('Adds a form for newsletter subscription.');
        $this->confirmUninstall = $this->l('Are you sure that you want to delete all of your contacts?');
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);

        $this->entity_manager = $entity_manager;

        $this->version = '1.0.0';
        $this->author = 'PrestaShop';
        $this->error = false;
        $this->valid = false;
        $this->_files = array(
            'name' => array('newsletter_conf', 'newsletter_voucher'),
            'ext' => array(
                0 => 'html',
                1 => 'txt'
            )
        );

        $this->_searched_email = null;

        $this->_html = '';
        if ($this->id) {
            $this->file = 'export_'.Configuration::get('PS_NEWSLETTER_RAND').'.csv';
            $this->post_valid = array();

            // Getting data...
            $countries = Country::getCountries($this->context->language->id);

            // ...formatting array
            $countries_list = array($this->l('All countries'));
            foreach ($countries as $country) {
                $countries_list[$country['id_country']] = $country['name'];
            }

            // And filling fields to show !
            $this->fields_export = array(
                'COUNTRY' => array(
                    'title' => $this->l('Customers\' country'),
                    'desc' => $this->l('Filter customers\' country.'),
                    'type' => 'select',
                    'value' => $countries_list,
                    'value_default' => 0
                ),
                'SUSCRIBERS' => array(
                    'title' => $this->l('Newsletter subscribers'),
                    'desc' => $this->l('Filter newsletter subscribers.'),
                    'type' => 'select',
                    'value' => array(
                        0 => $this->l('All customers'),
                        2 => $this->l('Subscribers'),
                        1 => $this->l('Non-subscribers')
                    ),
                    'value_default' => 2
                ),
                'OPTIN' => array(
                    'title' => $this->l('Opted-in subscribers'),
                    'desc' => $this->l('Filter opted-in subscribers.'),
                    'type' => 'select',
                    'value' => array(
                        0 => $this->l('All customers'),
                        2 => $this->l('Subscribers'),
                        1 => $this->l('Non-subscribers')
                    ),
                    'value_default' => 0
                ),
            );
        }
    }

    public function install()
    {
        if (!parent::install() || !Configuration::updateValue('PS_NEWSLETTER_RAND', rand().rand()) || !$this->registerHook(array('displayFooterBefore', 'actionCustomerAccountAdd'))) {
            return false;
        }

        Configuration::updateValue('NW_SALT', Tools::passwdGen(16));
        Configuration::updateValue('NW_CMS_PRIVACY_PAGE', 0);
        Configuration::updateValue('NW_CONFIRMATION_OPTIN', false);

        return Db::getInstance()->execute('
        CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'newsletter` (
            `id` int(6) NOT NULL AUTO_INCREMENT,
            `id_shop` INTEGER UNSIGNED NOT NULL DEFAULT \'1\',
            `id_shop_group` INTEGER UNSIGNED NOT NULL DEFAULT \'1\',
            `email` varchar(255) NOT NULL,
            `newsletter_date_add` DATETIME NULL,
            `ip_registration_newsletter` varchar(15) NOT NULL,
            `http_referer` VARCHAR(255) NULL,
            `active` TINYINT(1) NOT NULL DEFAULT \'0\',
            PRIMARY KEY(`id`)
        ) ENGINE='._MYSQL_ENGINE_.' default CHARSET=utf8');
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE '._DB_PREFIX_.'newsletter');

        return parent::uninstall();
    }

    public function getContent()
    {
        if (Tools::isSubmit('submitUpdate')) {
            Configuration::updateValue('NW_CONFIRMATION_EMAIL', (bool)Tools::getValue('NW_CONFIRMATION_EMAIL'));
            Configuration::updateValue('NW_VERIFICATION_EMAIL', (bool)Tools::getValue('NW_VERIFICATION_EMAIL'));
            $confirmation_optin = (bool)Tools::getValue('NW_CONFIRMATION_OPTIN');
            $cms_page_privacy_policy = (int)Tools::getValue('NW_CMS_PRIVACY_PAGE');
            if ($confirmation_optin && !$cms_page_privacy_policy) {
                $this->_html .= $this->displayError($this->l('Please choose a CMS page for privacy policy'));
            } else {
                Configuration::updateValue('NW_CONFIRMATION_OPTIN', $confirmation_optin);
                Configuration::updateValue('NW_CMS_PRIVACY_PAGE', $cms_page_privacy_policy);
            }

            $voucher = Tools::getValue('NW_VOUCHER_CODE');
            if ($voucher && !Validate::isDiscountName($voucher)) {
                $this->_html .= $this->displayError($this->l('The voucher code is invalid.'));
            } else {
                Configuration::updateValue('NW_VOUCHER_CODE', pSQL($voucher));
                $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
            }
        } elseif (Tools::isSubmit('subscribedmerged')) {
            $id = Tools::getValue('id');

            if (preg_match('/(^N)/', $id)) {
                $id = (int)substr($id, 1);
                $sql = 'UPDATE '._DB_PREFIX_.'newsletter SET active = 0 WHERE id = '.$id;
                Db::getInstance()->execute($sql);
            } else {
                $c = new Customer((int)$id);
                $c->newsletter = (int)!$c->newsletter;
                $c->update();
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&conf=4&token='.Tools::getAdminTokenLite('AdminModules'));
        } elseif (Tools::isSubmit('submitExport') && $action = Tools::getValue('action')) {
            $this->export_csv();
        } elseif (Tools::isSubmit('searchEmail')) {
            $this->_searched_email = Tools::getValue('searched_email');
        }

        $this->_html .= $this->renderForm();
        $this->_html .= $this->renderSearchForm();
        $this->_html .= $this->renderList();

        $this->_html .= $this->renderExportForm();

        return $this->_html;
    }

    public function renderList()
    {
        $fields_list = array(

            'id' => array(
                'title' => $this->l('ID'),
                'search' => false,
            ),
            'shop_name' => array(
                'title' => $this->l('Shop'),
                'search' => false,
            ),
            'gender' => array(
                'title' => $this->l('Gender'),
                'search' => false,
            ),
            'lastname' => array(
                'title' => $this->l('Lastname'),
                'search' => false,
            ),
            'firstname' => array(
                'title' => $this->l('Firstname'),
                'search' => false,
            ),
            'email' => array(
                'title' => $this->l('Email'),
                'search' => false,
            ),
            'subscribed' => array(
                'title' => $this->l('Subscribed'),
                'type' => 'bool',
                'active' => 'subscribed',
                'search' => false,
            ),
            'newsletter_date_add' => array(
                'title' => $this->l('Subscribed on'),
                'type' => 'date',
                'search' => false,
            )
        );

        if (!Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE')) {
            unset($fields_list['shop_name']);
        }

        $helper_list = new HelperList();
        $helper_list->module = $this;
        $helper_list->title = $this->l('Newsletter registrations');
        $helper_list->shopLinkType = '';
        $helper_list->no_link = true;
        $helper_list->show_toolbar = true;
        $helper_list->simple_header = false;
        $helper_list->identifier = 'id';
        $helper_list->table = 'merged';
        $helper_list->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name;
        $helper_list->token = Tools::getAdminTokenLite('AdminModules');
        $helper_list->actions = array('viewCustomer');

        /* Before 1.6.0.7 displayEnableLink() could not be overridden in Module class
           we declare another row action instead             */
        if (version_compare(_PS_VERSION_, '1.6.0.7', '<')) {
            unset($fields_list['subscribed']);
            $helper_list->actions = array_merge($helper_list->actions, array('unsubscribe'));
        }

        // This is needed for displayEnableLink to avoid code duplication
        $this->_helperlist = $helper_list;

        /* Retrieve list data */
        $subscribers = $this->getSubscribers();
        $helper_list->listTotal = count($subscribers);

        /* Paginate the result */
        $page = ($page = Tools::getValue('submitFilter'.$helper_list->table)) ? $page : 1;
        $pagination = ($pagination = Tools::getValue($helper_list->table.'_pagination')) ? $pagination : 50;
        $subscribers = $this->paginateSubscribers($subscribers, $page, $pagination);

        return $helper_list->generateList($subscribers, $fields_list);
    }

    public function displayViewCustomerLink($token = null, $id, $name = null)
    {
        $this->smarty->assign(array(
            'href' => 'index.php?controller=AdminCustomers&id_customer='.(int)$id.'&updatecustomer&token='.Tools::getAdminTokenLite('AdminCustomers'),
            'action' => $this->l('View'),
            'disable' => !((int)$id > 0),
        ));

        return $this->display(__FILE__, 'views/templates/admin/list_action_viewcustomer.tpl');
    }

    public function displayEnableLink($token, $id, $value, $active, $id_category = null, $id_product = null, $ajax = false)
    {
        $this->smarty->assign(array(
            'ajax' => $ajax,
            'enabled' => (bool)$value,
            'url_enable' => $this->_helperlist->currentIndex.'&'.$this->_helperlist->identifier.'='.$id.'&'.$active.$this->_helperlist->table.($ajax ? '&action='.$active.$this->_helperlist->table.'&ajax='.(int)$ajax : '').((int)$id_category && (int)$id_product ? '&id_category='.(int)$id_category : '').'&token='.$token
        ));

        return $this->display(__FILE__, 'views/templates/admin/list_action_enable.tpl');
    }

    public function displayUnsubscribeLink($token = null, $id, $name = null)
    {
        $this->smarty->assign(array(
            'href' => $this->_helperlist->currentIndex.'&subscribedcustomer&'.$this->_helperlist->identifier.'='.$id.'&token='.$token,
            'action' => $this->l('Unsubscribe'),
        ));

        return $this->display(__FILE__, 'views/templates/admin/list_action_unsubscribe.tpl');
    }

    /**
     * Check if this mail is registered for newsletters
     *
     * @param string $customer_email
     *
     * @return int -1 = not a customer and not registered
     *                0 = customer not registered
     *                1 = registered in block
     *                2 = registered in customer
     */
    public function isNewsletterRegistered($customer_email)
    {
        $sql = 'SELECT `email`
                FROM '._DB_PREFIX_.'newsletter
                WHERE `email` = \''.pSQL($customer_email).'\'
                AND id_shop = '.$this->context->shop->id;

        if (Db::getInstance()->getRow($sql)) {
            return self::GUEST_REGISTERED;
        }

        $sql = 'SELECT `newsletter`
                FROM '._DB_PREFIX_.'customer
                WHERE `email` = \''.pSQL($customer_email).'\'
                AND id_shop = '.$this->context->shop->id;

        if (!$registered = Db::getInstance()->getRow($sql)) {
            return self::GUEST_NOT_REGISTERED;
        }

        if ($registered['newsletter'] == '1') {
            return self::CUSTOMER_REGISTERED;
        }

        return self::CUSTOMER_NOT_REGISTERED;
    }

    /**
     * Register in email subscription
     */
    protected function newsletterRegistration()
    {
        if (empty($_POST['email']) || !Validate::isEmail($_POST['email'])) {
            return $this->error = $this->l('Invalid email address.');
        } elseif ($_POST['action'] == '1') {
            $register_status = $this->isNewsletterRegistered($_POST['email']);

            if ($register_status < 1) {
                return $this->error = $this->l('This email address is not registered.');
            }

            if (!$this->unregister($_POST['email'], $register_status)) {
                return $this->error = $this->l('An error occurred while attempting to unsubscribe.');
            }

            return $this->valid = $this->l('Unsubscription successful.');
        } elseif ($_POST['action'] == '0') {
            $register_status = $this->isNewsletterRegistered($_POST['email']);
            if ($register_status > 0) {
                return $this->error = $this->l('This email address is already registered.');
            }

            $email = pSQL($_POST['email']);
            if (!$this->isRegistered($register_status)) {
                if (Configuration::get('NW_VERIFICATION_EMAIL')) {
                    // create an unactive entry in the newsletter database
                    if ($register_status == self::GUEST_NOT_REGISTERED) {
                        $this->registerGuest($email, false);
                    }

                    if (!$token = $this->getToken($email, $register_status)) {
                        return $this->error = $this->l('An error occurred during the subscription process.');
                    }

                    $this->sendVerificationEmail($email, $token);

                    return $this->valid = $this->l('A verification email has been sent. Please check your inbox.');
                } else {
                    if((bool)Configuration::get('NW_CONFIRMATION_OPTIN') && empty($_POST['confirm-optin'])) {
                        return $this->error = $this->l('You must agree to receive the newsletter');
                    } elseif ($this->register($email, $register_status)) {
                        $this->valid = $this->l('You have successfully subscribed to this newsletter.');
                    } else {
                        return $this->error = $this->l('An error occurred during the subscription process.');
                    }

                    if ($code = Configuration::get('NW_VOUCHER_CODE')) {
                        $this->sendVoucher($email, $code);
                    }

                    if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
                        $this->sendConfirmationEmail($email);
                    }
                }
            }
        }
    }

    public function getSubscribers()
    {
        $dbquery = new DbQuery();
        $dbquery->select('c.`id_customer` AS `id`, s.`name` AS `shop_name`, gl.`name` AS `gender`, c.`lastname`, c.`firstname`, c.`email`, c.`newsletter` AS `subscribed`, c.`newsletter_date_add`');
        $dbquery->from('customer', 'c');
        $dbquery->leftJoin('shop', 's', 's.id_shop = c.id_shop');
        $dbquery->leftJoin('gender', 'g', 'g.id_gender = c.id_gender');
        $dbquery->leftJoin('gender_lang', 'gl', 'g.id_gender = gl.id_gender AND gl.id_lang = '.(int)$this->context->employee->id_lang);
        $dbquery->where('c.`newsletter` = 1');
        if ($this->_searched_email) {
            $dbquery->where('c.`email` LIKE \'%'.pSQL($this->_searched_email).'%\' ');
        }

        $customers = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($dbquery->build());

        $dbquery = new DbQuery();
        $dbquery->select('CONCAT(\'N\', n.`id`) AS `id`, s.`name` AS `shop_name`, NULL AS `gender`, NULL AS `lastname`, NULL AS `firstname`, n.`email`, n.`active` AS `subscribed`, n.`newsletter_date_add`');
        $dbquery->from('newsletter', 'n');
        $dbquery->leftJoin('shop', 's', 's.id_shop = n.id_shop');
        $dbquery->where('n.`active` = 1');
        if ($this->_searched_email) {
            $dbquery->where('n.`email` LIKE \'%'.pSQL($this->_searched_email).'%\' ');
        }

        $non_customers = Db::getInstance()->executeS($dbquery->build());

        $subscribers = array_merge($customers, $non_customers);

        return $subscribers;
    }

    public function paginateSubscribers($subscribers, $page = 1, $pagination = 50)
    {
        if (count($subscribers) > $pagination) {
            $subscribers = array_slice($subscribers, $pagination * ($page - 1), $pagination);
        }

        return $subscribers;
    }

    /**
     * Return true if the registered status correspond to a registered user
     *
     * @param int $register_status
     *
     * @return bool
     */
    protected function isRegistered($register_status)
    {
        return in_array(
            $register_status,
            array(self::GUEST_REGISTERED, self::CUSTOMER_REGISTERED)
        );
    }


    /**
     * Subscribe an email to the newsletter. It will create an entry in the newsletter table
     * or update the customer table depending of the register status
     *
     * @param string $email
     * @param int    $register_status
     */
    protected function register($email, $register_status)
    {
        if ($register_status == self::GUEST_NOT_REGISTERED) {
            return $this->registerGuest($email);
        }

        if ($register_status == self::CUSTOMER_NOT_REGISTERED) {
            return $this->registerUser($email);
        }

        return false;
    }

    protected function unregister($email, $register_status)
    {
        if ($register_status == self::GUEST_REGISTERED) {
            $sql = 'DELETE FROM '._DB_PREFIX_.'newsletter WHERE `email` = \''.pSQL($_POST['email']).'\' AND id_shop = '.$this->context->shop->id;
        } elseif ($register_status == self::CUSTOMER_REGISTERED) {
            $sql = 'UPDATE '._DB_PREFIX_.'customer SET `newsletter` = 0 WHERE `email` = \''.pSQL($_POST['email']).'\' AND id_shop = '.$this->context->shop->id;
        }

        if (!isset($sql) || !Db::getInstance()->execute($sql)) {
            return false;
        }

        return true;
    }

    /**
     * Subscribe a customer to the newsletter
     *
     * @param string $email
     *
     * @return bool
     */
    protected function registerUser($email)
    {
        $sql = 'UPDATE '._DB_PREFIX_.'customer
                SET `newsletter` = 1, newsletter_date_add = NOW(), `ip_registration_newsletter` = \''.pSQL(Tools::getRemoteAddr()).'\'
                WHERE `email` = \''.pSQL($email).'\'
                AND id_shop = '.$this->context->shop->id;

        return Db::getInstance()->execute($sql);
    }

    /**
     * Subscribe a guest to the newsletter
     *
     * @param string $email
     * @param bool   $active
     *
     * @return bool
     */
    protected function registerGuest($email, $active = true)
    {
        $sql = 'INSERT INTO '._DB_PREFIX_.'newsletter (id_shop, id_shop_group, email, newsletter_date_add, ip_registration_newsletter, http_referer, active)
                VALUES
                ('.$this->context->shop->id.',
                '.$this->context->shop->id_shop_group.',
                \''.pSQL($email).'\',
                NOW(),
                \''.pSQL(Tools::getRemoteAddr()).'\',
                (
                    SELECT c.http_referer
                    FROM '._DB_PREFIX_.'connections c
                    WHERE c.id_guest = '.(int)$this->context->customer->id.'
                    ORDER BY c.date_add DESC LIMIT 1
                ),
                '.(int)$active.'
                )';

        return Db::getInstance()->execute($sql);
    }


    public function activateGuest($email)
    {
        return Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'newsletter`
                        SET `active` = 1
                        WHERE `email` = \''.pSQL($email).'\''
        );
    }

    /**
     * Returns a guest email by token
     *
     * @param string $token
     *
     * @return string email
     */
    protected function getGuestEmailByToken($token)
    {
        $sql = 'SELECT `email`
                FROM `'._DB_PREFIX_.'newsletter`
                WHERE MD5(CONCAT( `email` , `newsletter_date_add`, \''.pSQL(Configuration::get('NW_SALT')).'\')) = \''.pSQL($token).'\'
                AND `active` = 0';

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Returns a customer email by token
     *
     * @param string $token
     *
     * @return string email
     */
    protected function getUserEmailByToken($token)
    {
        $sql = 'SELECT `email`
                FROM `'._DB_PREFIX_.'customer`
                WHERE MD5(CONCAT( `email` , `date_add`, \''.pSQL(Configuration::get('NW_SALT')).'\')) = \''.pSQL($token).'\'
                AND `newsletter` = 0';

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Return a token associated to an user
     *
     * @param string $email
     * @param string $register_status
     */
    protected function getToken($email, $register_status)
    {
        if (in_array($register_status, array(self::GUEST_NOT_REGISTERED, self::GUEST_REGISTERED))) {
            $sql = 'SELECT MD5(CONCAT( `email` , `newsletter_date_add`, \''.pSQL(Configuration::get('NW_SALT')).'\')) as token
                    FROM `'._DB_PREFIX_.'newsletter`
                    WHERE `active` = 0
                    AND `email` = \''.pSQL($email).'\'';
        } elseif ($register_status == self::CUSTOMER_NOT_REGISTERED) {
            $sql = 'SELECT MD5(CONCAT( `email` , `date_add`, \''.pSQL(Configuration::get('NW_SALT')).'\' )) as token
                    FROM `'._DB_PREFIX_.'customer`
                    WHERE `newsletter` = 0
                    AND `email` = \''.pSQL($email).'\'';
        }

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Ends the registration process to the newsletter
     *
     * @param string $token
     *
     * @return string
     */
    public function confirmEmail($token)
    {
        $activated = false;

        if ($email = $this->getGuestEmailByToken($token)) {
            $activated = $this->activateGuest($email);
        } elseif ($email = $this->getUserEmailByToken($token)) {
            $activated = $this->registerUser($email);
        }

        if (!$activated) {
            return $this->l('This email is already registered and/or invalid.');
        }

        if ($discount = Configuration::get('NW_VOUCHER_CODE')) {
            $this->sendVoucher($email, $discount);
        }

        if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
            $this->sendConfirmationEmail($email);
        }

        return $this->l('Thank you for subscribing to our newsletter.');
    }

    /**
     * Send the confirmation mails to the given $email address if needed.
     *
     * @param string $email Email where to send the confirmation
     *
     * @note the email has been verified and might not yet been registered. Called by AuthController::processCustomerNewsletter
     *
     */
    public function confirmSubscription($email)
    {
        if ($email) {
            if ($discount = Configuration::get('NW_VOUCHER_CODE')) {
                $this->sendVoucher($email, $discount);
            }

            if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
                $this->sendConfirmationEmail($email);
            }
        }
    }

    /**
     * Send an email containing a voucher code
     *
     * @param $email
     * @param $code
     *
     * @return bool|int
     */
    protected function sendVoucher($email, $code)
    {
        return Mail::Send($this->context->language->id, 'newsletter_voucher', Mail::l('Newsletter voucher', $this->context->language->id), array('{discount}' => $code), $email, null, null, null, null, null, dirname(__FILE__).'/mails/', false, $this->context->shop->id);
    }

    /**
     * Send a confirmation email
     *
     * @param string $email
     *
     * @return bool
     */
    protected function sendConfirmationEmail($email)
    {
        return Mail::Send($this->context->language->id, 'newsletter_conf', Mail::l('Newsletter confirmation', $this->context->language->id), array(), pSQL($email), null, null, null, null, null, dirname(__FILE__).'/mails/', false, $this->context->shop->id);
    }

    /**
     * Send a verification email
     *
     * @param string $email
     * @param string $token
     *
     * @return bool
     */
    protected function sendVerificationEmail($email, $token)
    {
        $verif_url = Context::getContext()->link->getModuleLink(
            'ps_emailsubscription', 'verification', array(
                'token' => $token,
            )
        );

        return Mail::Send($this->context->language->id, 'newsletter_verif', Mail::l('Email verification', $this->context->language->id), array('{verif_url}' => $verif_url), $email, null, null, null, null, null, dirname(__FILE__).'/mails/', false, $this->context->shop->id);
    }

    public function renderWidget($hookName = null, array $configuration = [])
    {
        if (!$this->isCached('ps_emailsubscription.tpl', $this->getCacheId()) && !$this->error && !$this->valid) {
            $this->smarty->assign($this->getWidgetVariables($hookName, $configuration));
        }

        return $this->display(__FILE__, 'ps_emailsubscription.tpl', $this->getCacheId());
    }

    public function getWidgetVariables($hookName = null, array $configuration = [])
    {
        $variables = [];

        $variables['value'] = Tools::getValue('email', '');
        $variables['msg'] = '';
        $variables['need_confirmation'] = Configuration::get('NW_CONFIRMATION_OPTIN');
        $variables['cms_page'] = Configuration::get('NW_CMS_PRIVACY_PAGE');

        if (Tools::isSubmit('submitNewsletter')) {
            $this->newsletterRegistration();
            if ($this->error) {
                $variables['msg'] = $this->error;
                $variables['nw_error'] = true;
            } elseif ($this->valid) {
                $variables['msg'] = $this->valid;
                $variables['nw_error'] = false;
            }
        }

        return $variables;
    }

    /**
     * Deletes duplicates email in newsletter table
     *
     * @param $params
     *
     * @return bool
     */
    public function hookActionCustomerAccountAdd($params)
    {
        //if e-mail of the created user address has already been added to the newsletter through the ps_emailsubscription module,
        //we delete it from ps_emailsubscription table to prevent duplicates
        $id_shop = $params['newCustomer']->id_shop;
        $email = $params['newCustomer']->email;
        if (Validate::isEmail($email)) {
            return (bool)Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.'newsletter WHERE id_shop='.(int)$id_shop.' AND email=\''.pSQL($email)."'");
        }

        return true;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Would you like to send a verification email after subscription?'),
                        'name' => 'NW_VERIFICATION_EMAIL',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Would you like to send a confirmation email after subscription?'),
                        'name' => 'NW_CONFIRMATION_EMAIL',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Welcome voucher code'),
                        'name' => 'NW_VOUCHER_CODE',
                        'class' => 'fixed-width-md',
                        'desc' => $this->l('Leave blank to disable by default.')
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Require customers to accept privacy policy before subscribing.'),
                        'name' => 'NW_CONFIRMATION_OPTIN',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No')
                            )
                        ),
                        'default_value' => 0
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('CMS page for privacy policy'),
                        'desc' => $this->l('Choose the CMS page that contains your store\'s privacy policy.'),
                        'name' => 'NW_CMS_PRIVACY_PAGE',
                        'options' => array(
                            'query' => $this->getCMSRoles(),
                            'id' => 'id',
                            'name' => 'name',
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitUpdate';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function renderExportForm()
    {
        // Getting data...
        $countries = Country::getCountries($this->context->language->id);

        // ...formatting array
        $countries_list = array(array('id' => 0, 'name' => $this->l('All countries')));
        foreach ($countries as $country) {
            $countries_list[] = array('id' => $country['id_country'], 'name' => $country['name']);
        }

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Export customers\' addresses'),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Customers\' country'),
                        'desc' => $this->l('Filter customers by country.'),
                        'name' => 'COUNTRY',
                        'required' => false,
                        'default_value' => (int)$this->context->country->id,
                        'options' => array(
                            'query' => $countries_list,
                            'id' => 'id',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Newsletter subscribers'),
                        'desc' => $this->l('Filter customers who have subscribed to the newsletter or not, and who have an account or not.'),
                        'hint' => $this->l('Customers can subscribe to your newsletter when registering, or by entering their email in the newsletter form.'),
                        'name' => 'SUSCRIBERS',
                        'required' => false,
                        'default_value' => (int)$this->context->country->id,
                        'options' => array(
                            'query' => array(
                                array('id' => 0, 'name' => $this->l('All subscribers')),
                                array('id' => 1, 'name' => $this->l('Subscribers with account')),
                                array('id' => 2, 'name' => $this->l('Subscribers without account')),
                                array('id' => 3, 'name' => $this->l('Non-subscribers'))
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Opt-in subscribers'),
                        'desc' => $this->l('Filter customers who have agreed to receive your partners\' offers or not.'),
                        'hint' => $this->l('Opt-in subscribers have agreed to receive your partners\' offers.'),
                        'name' => 'OPTIN',
                        'required' => false,
                        'default_value' => (int)$this->context->country->id,
                        'options' => array(
                            'query' => array(
                                array('id' => 0, 'name' => $this->l('All customers')),
                                array('id' => 2, 'name' => $this->l('Opt-in subscribers')),
                                array('id' => 1, 'name' => $this->l('Opt-in non-subscribers'))
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'hidden',
                        'name' => 'action',
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Export .CSV file'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitExport',
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function renderSearchForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Search for addresses'),
                    'icon' => 'icon-search'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Email address to search'),
                        'name' => 'searched_email',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->l('Example: contact@prestashop.com or @prestashop.com')
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Search'),
                    'icon' => 'process-icon-refresh',
                )
            ),
        );

        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'searchEmail';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => array('searched_email' => $this->_searched_email),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'NW_VERIFICATION_EMAIL' => Tools::getValue('NW_VERIFICATION_EMAIL', Configuration::get('NW_VERIFICATION_EMAIL')),
            'NW_CONFIRMATION_EMAIL' => Tools::getValue('NW_CONFIRMATION_EMAIL', Configuration::get('NW_CONFIRMATION_EMAIL')),
            'NW_VOUCHER_CODE' => Tools::getValue('NW_VOUCHER_CODE', Configuration::get('NW_VOUCHER_CODE')),
            'NW_CONFIRMATION_OPTIN' => Tools::getValue('NW_CONFIRMATION_OPTIN', Configuration::get('NW_CONFIRMATION_OPTIN')),
            'NW_CMS_PRIVACY_PAGE' => Tools::getValue('NW_CMS_PRIVACY_PAGE', Configuration::get('NW_CMS_PRIVACY_PAGE')),
            'COUNTRY' => Tools::getValue('COUNTRY'),
            'SUSCRIBERS' => Tools::getValue('SUSCRIBERS'),
            'OPTIN' => Tools::getValue('OPTIN'),
            'action' => 'customers',
        );
    }

    public function export_csv()
    {
        if (!isset($this->context)) {
            $this->context = Context::getContext();
        }

        $result = $this->getCustomers();

        if ($result) {
            if (!$nb = count($result)) {
                $this->_html .= $this->displayError($this->l('No customers found with these filters!'));
            } elseif ($fd = @fopen(dirname(__FILE__).'/'.strval(preg_replace('#\.{2,}#', '.', Tools::getValue('action'))).'_'.$this->file, 'w')) {
                $header = array('id', 'shop_name', 'gender', 'lastname', 'firstname', 'email', 'subscribed', 'subscribed_on');
                $array_to_export = array_merge(array($header), $result);
                foreach ($array_to_export as $tab) {
                    $this->myFputCsv($fd, $tab);
                }
                fclose($fd);
                $this->_html .= $this->displayConfirmation(
                    sprintf($this->l('The .CSV file has been successfully exported: %d customers found.'), $nb).'<br />
                <a href="'.$this->context->shop->getBaseURI().'modules/ps_emailsubscription/'.Tools::safeOutput(strval(Tools::getValue('action'))).'_'.$this->file.'">
                <b>'.$this->l('Download the file').' '.$this->file.'</b>
                </a>
                <br />
                <ol style="margin-top: 10px;">
                    <li style="color: red;">'.
                    $this->l('WARNING: When opening this .csv file with Excel, choose UTF-8 encoding to avoid strange characters.').
                    '</li>
                </ol>');
            } else {
                $this->_html .= $this->displayError($this->l('Error: Write access limited').' '.dirname(__FILE__).'/'.strval(Tools::getValue('action')).'_'.$this->file.' !');
            }
        } else {
            $this->_html .= $this->displayError($this->l('No result found!'));
        }
    }

    private function getCMSRoles()
    {
        $cms_repository = $this->entity_manager->getRepository('CMS');
        $id_lang = Context::getContext()->employee->id_lang;
        $id_shop = Context::getContext()->shop->id;
        $cms_pages = array();
        
        $fake_object = new stdClass();
        $fake_object->id = 0;
        $fake_object->name = $this->l('-- Select associated CMS page --', $this->name);
        $cms_pages[-1] = $fake_object;
        unset($fake_object);

        foreach( $cms_repository->i10nFindAll($id_lang, $id_shop) as $cms_page) {
            $object = new stdClass();
            $object->id = $cms_page->id;
            $object->name = $cms_page->meta_title;
            $cms_pages[] =$object;
        }

        return $cms_pages;
    }

    private function getCustomers()
    {
        $id_shop = false;

        // Get the value to know with subscrib I need to take 1 with account 2 without 0 both 3 not subscrib
        $who = (int)Tools::getValue('SUSCRIBERS');

        // get optin 0 for all 1 no optin 2 with optin
        $optin = (int)Tools::getValue('OPTIN');

        $country = (int)Tools::getValue('COUNTRY');

        if (Context::getContext()->cookie->shopContext) {
            $id_shop = (int)Context::getContext()->shop->id;
        }

        $customers = array();
        if ($who == 1 || $who == 0 || $who == 3) {
            $dbquery = new DbQuery();
            $dbquery->select('c.`id_customer` AS `id`, s.`name` AS `shop_name`, gl.`name` AS `gender`, c.`lastname`, c.`firstname`, c.`email`, c.`newsletter` AS `subscribed`, c.`newsletter_date_add`');
            $dbquery->from('customer', 'c');
            $dbquery->leftJoin('shop', 's', 's.id_shop = c.id_shop');
            $dbquery->leftJoin('gender', 'g', 'g.id_gender = c.id_gender');
            $dbquery->leftJoin('gender_lang', 'gl', 'g.id_gender = gl.id_gender AND gl.id_lang = '.$this->context->employee->id_lang);
            $dbquery->where('c.`newsletter` = '.($who == 3 ? 0 : 1));
            if ($optin == 2 || $optin == 1) {
                $dbquery->where('c.`optin` = '.($optin == 1 ? 0 : 1));
            }
            if ($country) {
                $dbquery->where('(SELECT COUNT(a.`id_address`) as nb_country
                                                    FROM `'._DB_PREFIX_.'address` a
                                                    WHERE a.deleted = 0
                                                    AND a.`id_customer` = c.`id_customer`
                                                    AND a.`id_country` = '.$country.') >= 1');
            }
            if ($id_shop) {
                $dbquery->where('c.`id_shop` = '.$id_shop);
            }

            $customers = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($dbquery->build());
        }

        $non_customers = array();
        if (($who == 0 || $who == 2) && (!$optin || $optin == 2) && !$country) {
            $dbquery = new DbQuery();
            $dbquery->select('CONCAT(\'N\', n.`id`) AS `id`, s.`name` AS `shop_name`, NULL AS `gender`, NULL AS `lastname`, NULL AS `firstname`, n.`email`, n.`active` AS `subscribed`, n.`newsletter_date_add`');
            $dbquery->from('newsletter', 'n');
            $dbquery->leftJoin('shop', 's', 's.id_shop = n.id_shop');
            $dbquery->where('n.`active` = 1');
            if ($id_shop) {
                $dbquery->where('n.`id_shop` = '.$id_shop);
            }
            $non_customers = Db::getInstance()->executeS($dbquery->build());
        }

        $subscribers = array_merge($customers, $non_customers);

        return $subscribers;
    }

    private function myFputCsv($fd, $array)
    {
        $line = implode(';', $array);
        $line .= "\n";
        if (!fwrite($fd, $line, 4096)) {
            $this->post_errors[] = $this->l('Error: Write access limited').' '.dirname(__FILE__).'/'.$this->file.' !';
        }
    }
}
