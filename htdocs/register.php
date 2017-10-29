<?php
include '../lib/common.php';

//$_REQUEST['register']['first_name'] = (!empty($_REQUEST['register']['first_name'])) ? preg_replace("/[^\pL a-zA-Z0-9@\s\._-]/u", "",$_REQUEST['register']['first_name']) : false;
//$_REQUEST['register']['last_name'] = (!empty($_REQUEST['register']['last_name'])) ? preg_replace("/[^\pL a-zA-Z0-9@\s\._-]/u", "",$_REQUEST['register']['last_name']) : false;
$_REQUEST['register']['country'] = (!empty($_REQUEST['register']['country'])) ? preg_replace("/[^0-9]/", "",$_REQUEST['register']['country']) : false;
$_REQUEST['register']['email'] = (!empty($_REQUEST['register']['email'])) ? preg_replace("/[^0-9a-zA-Z@\.\!#\$%\&\*+_\~\?\-]/", "",$_REQUEST['register']['email']) : false;
$_REQUEST['register']['default_currency'] = (!empty($_REQUEST['register']['default_currency'])) ? preg_replace("/[^0-9]/", "",$_REQUEST['register']['default_currency']) : false;

if (empty($CFG->google_recaptch_api_key) || empty($CFG->google_recaptch_api_secret))
	$_REQUEST['is_caco'] = (!empty($_REQUEST['form_name']) && empty($_REQUEST['is_caco'])) ? array('register'=>1) : (!empty($_REQUEST['is_caco']) ? $_REQUEST['is_caco'] : false);

if (empty($_REQUEST['form_name']))
	unset($_REQUEST['register']);

$register = new Form('register',false,false,'form3');
unset($register->info['uniq']);
$register->verify();
$register->reCaptchaCheck();

if (!empty($_REQUEST['start']))
	$register->info['email'] = preg_replace("/[^0-9a-zA-Z@\.\!#\$%\&\*+_\~\?\-]/", "",$_REQUEST['email']);


if (!empty($_REQUEST['register']) && $_REQUEST['register']['default_currency'] == $_REQUEST['register']['default_c_currency']) {
	$register->errors[] = Lang::string('same-currency-error');
}

if (!empty($_REQUEST['register']) && (empty($_SESSION["register_uniq"]) || $_SESSION["register_uniq"] != $_REQUEST['register']['uniq']))
	$register->errors[] = 'Page expired.';

if (!empty($_REQUEST['register']) && !$register->info['terms'])
	$register->errors[] = Lang::string('settings-terms-error');

if (!empty($_REQUEST['register']) && $CFG->register_status == 'suspended')
	$register->errors[] = Lang::string('register-disabled');

if (!empty($_REQUEST['register']) && (is_array($register->errors))) {
	$errors = array();
	
	if ($register->errors) {
		foreach ($register->errors as $key => $error) {
			if (stristr($error,'login-required-error')) {
				$errors[] = Lang::string('settings-'.str_replace('_','-',$key)).' '.Lang::string('login-required-error');
			}
			elseif (strstr($error,'-')) {
				$errors[] = Lang::string($error);
			}
			else {
				$errors[] = $error;
			}
		}
	}
		
	Errors::$errors = $errors;
}
elseif (!empty($_REQUEST['register']) && !is_array($register->errors)) {
	API::add('User','registerNew',array($register->info));
	$query = API::send();
	
	$_SESSION["register_uniq"] = md5(uniqid(mt_rand(),true));
	Link::redirect($CFG->baseurl.'login.php?message=registered');
}

API::add('User','getCountries');
$query = API::send();
//$countries = $query['User']['getCountries']['results'][0];

$page_title = Lang::string('home-register');

$_SESSION["register_uniq"] = md5(uniqid(mt_rand(),true));
include 'includes/head.php';
?>
<div class="" style="width: 100%; float: left; background-color: #006eaf;">
	<div class="contenedor-registro" style="width: 50%; margin-left: auto; margin-right: auto; text-align: center;">
        <h2 style="padding: 4%;color: #ffffff;font-size: 2.5em;">Registrar</h2>
		<div class="" style="background-color: rgba(255, 255, 255, 0.60); padding: 5%;">
			<? 
            Errors::display(); 
            Messages::display();
            ?>
            <div class="content content-registrer" style="border: none;">
            	<img src="images/BTC.svg" alt="BTC" style="margin: 0;width: 50%;padding: 0;">
                <div class="clear"></div>
                <?
                $currencies_list = array();
                if ($CFG->currencies) {
                	foreach ($CFG->currencies as $key => $currency) {
                		if (is_numeric($key))
                			continue;
                		
                		$currencies_list[$key] = $currency;
                	}
                }
                
                $currencies_list1 = array();
                if ($CFG->currencies) {
                	foreach ($CFG->currencies as $key => $currency) {
                		if (is_numeric($key) || $currency['is_crypto'] != 'Y')
                			continue;
                
                		$currencies_list1[$key] = $currency;
                	}
                }
                
				//$register->textInput('first_name',Lang::string('settings-first-name'),false);
                //$register->textInput('last_name',Lang::string('settings-last-name'),false);
                //$register->selectInput('country',Lang::string('settings-country'),false,false,$countries,false,array('name'));
                $register->textInput('email',Lang::string('settings-email'),'email');
                $register->selectInput('default_c_currency',Lang::string('default-c-currency'),1,false,$currencies_list1,false,array('currency'));
                $register->selectInput('default_currency',Lang::string('default-currency'),1,false,$currencies_list,false,array('currency'));
                $register->checkBox('terms',Lang::string('settings-terms-accept'),false,false,false,false,false,false,'checkbox_label');
                $register->captcha(Lang::string('settings-capcha'));
                $register->HTML('<div class="form_button"><input type="submit" name="submit" value="'.Lang::string('home-register').'" class="but_user" style="background: #fe510d;"/></div>');
                $register->hiddenInput('uniq',1,$_SESSION["register_uniq"]);
                $register->display();
                ?>
            	<div class="clear"></div>
            </div>
            <div class="mar_top8"></div>
        </div>
	</div>
</div>
<? include 'includes/foot.php'; ?>
