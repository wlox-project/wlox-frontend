<?php
include '../lib/common.php';

if (User::$info['locked'] == 'Y' || User::$info['deactivated'] == 'Y')
	Link::redirect('settings.php');
elseif (User::$awaiting_token)
	Link::redirect('verify-token.php');
elseif (!User::isLoggedIn())
	Link::redirect('login.php');

if (empty($_REQUEST) && empty($_SESSION['currency']) && !empty(User::$info['default_currency_abbr']))
	$_SESSION['currency'] = User::$info['default_currency_abbr'];
elseif (empty($_REQUEST) && empty($_SESSION['currency']) && empty(User::$info['default_currency_abbr']))
	$_SESSION['currency'] = 'usd';
elseif (!empty($_REQUEST['currency']))
	$_SESSION['currency'] = preg_replace("/[^a-z]/", "",$_REQUEST['currency']);

if (empty($CFG->currencies[strtoupper($_SESSION['currency'])]))
	$_SESSION['currency'] = 'usd';

if (empty($_REQUEST) && empty($_SESSION['c_currency']))
	$_SESSION['c_currency'] = 'btc';
elseif (!empty($_REQUEST['c_currency']))
	$_SESSION['c_currency'] = preg_replace("/[^a-z]/", "",$_REQUEST['c_currency']);

if (empty($CFG->currencies[strtoupper($_SESSION['c_currency'])]))
	$_SESSION['c_currency'] = 'btc';


$buy = (!empty($_REQUEST['buy']));
$sell = (!empty($_REQUEST['sell']));
$ask_confirm = false;
$currency1 = preg_replace("/[^a-z]/", "",strtolower($_SESSION['currency']));
$c_currency1 = preg_replace("/[^a-z]/", "",strtolower($_SESSION['c_currency']));
$currency_info = $CFG->currencies[strtoupper($currency1)];
$confirmed = (!empty($_REQUEST['confirmed'])) ? $_REQUEST['confirmed'] : false;
$cancel = (!empty($_REQUEST['cancel'])) ? $_REQUEST['cancel'] : false;
$bypass = (!empty($_REQUEST['bypass'])) ? $_REQUEST['bypass'] : false;
$buy_market_price1 = 0;
$sell_market_price1 = 0;
$buy_limit = 1;
$sell_limit = 1;

if ($buy || $sell) {
	if (empty($_SESSION["buysell_uniq"]) || empty($_REQUEST['uniq']) || !in_array($_REQUEST['uniq'],$_SESSION["buysell_uniq"]))
		Errors::add('Page expired.');
}

API::add('FeeSchedule','getRecord',array(User::$info['fee_schedule']));
API::add('User','getAvailable');
API::add('Orders','getBidAsk',array($currency1));
API::add('Orders','get',array(false,false,10,$currency1,false,false,1));
API::add('Orders','get',array(false,false,10,$currency1,false,false,false,false,1));
API::add('BankAccounts','get',array($currency_info['id']));
API::add('Stats','getCurrent',array($currency_info['id']));
API::add('Transactions','get',array(false,false,1,$currency1));
$query = API::send();

$user_fee_both = $query['FeeSchedule']['getRecord']['results'][0];
$user_available = $query['User']['getAvailable']['results'][0];
$current_bid = $query['Orders']['getBidAsk']['results'][0]['bid'];
$current_ask =  $query['Orders']['getBidAsk']['results'][0]['ask'];
$bids = $query['Orders']['get']['results'][0];
$asks = $query['Orders']['get']['results'][1];
$user_fee_bid = ($buy && ((!empty($_REQUEST['buy_amount']) && $_REQUEST['buy_amount'] > 0 && $_REQUEST['buy_price'] >= $asks[0]['btc_price']) || !empty($_REQUEST['buy_market_price']) || empty($_REQUEST['buy_amount']))) ? $query['FeeSchedule']['getRecord']['results'][0]['fee'] : $query['FeeSchedule']['getRecord']['results'][0]['fee1'];
$user_fee_ask = ($sell && ((!empty($_REQUEST['sell_amount']) && $_REQUEST['sell_amount'] > 0 && $_REQUEST['sell_price'] <= $bids[0]['btc_price']) || !empty($_REQUEST['sell_market_price']) || empty($_REQUEST['sell_amount']))) ? $query['FeeSchedule']['getRecord']['results'][0]['fee'] : $query['FeeSchedule']['getRecord']['results'][0]['fee1'];
$bank_accounts = $query['BankAccounts']['get']['results'][0];
$stats = $query['Stats']['getCurrent']['results'][0];
$transactions = $query['Transactions']['get']['results'][0];
$usd_field = 'usd_ask';

$buy_amount1 = (!empty($_REQUEST['buy_price']) && $_REQUEST['buy_amount'] > 0) ? rtrim(number_format(preg_replace("/[^0-9.]/", "",$_REQUEST['buy_amount']),8,'.',''),'0') : 0;
$buy_price1 = (!empty($_REQUEST['buy_price']) && $_REQUEST['buy_price'] > 0) ? rtrim(number_format(preg_replace("/[^0-9.]/", "",$_REQUEST['buy_price']),2,'.',''),'0') : $current_ask;
$buy_subtotal1 = $buy_amount1 * $buy_price1;
$buy_fee_amount1 = ($user_fee_bid * 0.01) * $buy_subtotal1;
$buy_total1 = round($buy_subtotal1 + $buy_fee_amount1,2,PHP_ROUND_HALF_UP);
$buy_stop = false;
$buy_stop_price1 = false;

$sell_amount1 = (!empty($_REQUEST['sell_amount']) && $_REQUEST['sell_amount'] > 0) ? rtrim(number_format(preg_replace("/[^0-9.]/", "",$_REQUEST['sell_amount']),8,'.',''),'0') : 0;
$sell_price1 = (!empty($_REQUEST['sell_price']) && $_REQUEST['sell_price'] > 0) ? rtrim(number_format(preg_replace("/[^0-9.]/", "",$_REQUEST['sell_price']),2,'.',''),'0') : $current_bid;
$sell_subtotal1 = $sell_amount1 * $sell_price1;
$sell_fee_amount1 = ($user_fee_ask * 0.01) * $sell_subtotal1;
$sell_total1 = round($sell_subtotal1 - $sell_fee_amount1,2,PHP_ROUND_HALF_UP);
$sell_stop = false;
$sell_stop_price1 = false;

if ($CFG->trading_status == 'suspended')
	Errors::add(Lang::string('buy-trading-disabled'));

if ($buy && !is_array(Errors::$errors)) {
	$buy_market_price1 = (!empty($_REQUEST['buy_market_price']));
	$buy_price1 = ($buy_market_price1) ? $current_ask : $buy_price1;
	$buy_stop = (!empty($_REQUEST['buy_stop']));
	$buy_stop_price1 = ($buy_stop) ? rtrim(number_format(preg_replace("/[^0-9.]/", "",$_REQUEST['buy_stop_price']),2,'.',''),'0') : false;
	$buy_limit = (!empty($_REQUEST['buy_limit']));
	$buy_limit = (!$buy_stop && !$buy_market_price1) ? 1 : $buy_limit;
	
	if (!$confirmed && !$cancel) {
		API::add('Orders','checkPreconditions',array(1,$currency_info,$buy_amount1,(($buy_stop && !$buy_limit) ? $buy_stop_price1 : $buy_price1),$buy_stop_price1,$user_fee_bid,$user_available[strtoupper($currency1)],$current_bid,$current_ask,$buy_market_price1));
		if (!$buy_market_price1)
			API::add('Orders','checkUserOrders',array(1,$currency_info,false,(($buy_stop && !$buy_limit) ? $buy_stop_price1 : $buy_price1),$buy_stop_price1,$user_fee_bid,$buy_stop));
		
		$query = API::send();
		$errors1 = $query['Orders']['checkPreconditions']['results'][0];
		if (!empty($errors1['error']))
			Errors::add($errors1['error']['message']);
		$errors2 = (!empty($query['Orders']['checkUserOrders']['results'][0])) ? $query['Orders']['checkUserOrders']['results'][0] : false;
		if (!empty($errors2['error']))
			Errors::add($errors2['error']['message']);
		
		if (!$errors1 && !$errors2)
			$ask_confirm = true;
	}
	else if (!$cancel) {
		API::add('Orders','executeOrder',array(1,(($buy_stop && !$buy_limit) ? $buy_stop_price1 : $buy_price1),$buy_amount1,$currency1,$user_fee_bid,$buy_market_price1,false,false,false,$buy_stop_price1));
		$query = API::send();
		$operations = $query['Orders']['executeOrder']['results'][0];
		
		if (!empty($operations['error'])) {
			Errors::add($operations['error']['message']);
		}
		else if ($operations['new_order'] > 0) {
		    $_SESSION["buysell_uniq"][time()] = md5(uniqid(mt_rand(),true));
		    if (count($_SESSION["buysell_uniq"]) > 3) {
		    	unset($_SESSION["buysell_uniq"][min(array_keys($_SESSION["buysell_uniq"]))]);
		    }
		    
			Link::redirect('open-orders.php',array('transactions'=>$operations['transactions'],'new_order'=>1));
			exit;
		}
		else {
		    $_SESSION["buysell_uniq"][time()] = md5(uniqid(mt_rand(),true));
		    if (count($_SESSION["buysell_uniq"]) > 3) {
		    	unset($_SESSION["buysell_uniq"][min(array_keys($_SESSION["buysell_uniq"]))]);
		    }
		    
			Link::redirect('transactions.php',array('transactions'=>$operations['transactions']));
			exit;
		}
	}
}

if ($sell && !is_array(Errors::$errors)) {
	$sell_market_price1 = (!empty($_REQUEST['sell_market_price']));
	$sell_price1 = ($sell_market_price1) ? $current_bid : $sell_price1;
	$sell_stop = (!empty($_REQUEST['sell_stop']));
	$sell_stop_price1 = ($sell_stop) ? rtrim(number_format(preg_replace("/[^0-9.]/", "",$_REQUEST['sell_stop_price']),2,'.',''),'0') : false;
	$sell_limit = (!empty($_REQUEST['sell_limit']));
	$sell_limit = (!$sell_stop && !$sell_market_price1) ? 1 : $sell_limit;
	
	if (!$confirmed && !$cancel) {
		API::add('Orders','checkPreconditions',array(0,$currency_info,$sell_amount1,(($sell_stop && !$sell_limit) ? $sell_stop_price1 : $sell_price1),$sell_stop_price1,$user_fee_ask,$user_available['BTC'],$current_bid,$current_ask,$sell_market_price1));
		if (!$sell_market_price1)
			API::add('Orders','checkUserOrders',array(0,$currency_info,false,(($sell_stop && !$sell_limit) ? $sell_stop_price1 : $sell_price1),$sell_stop_price1,$user_fee_ask,$sell_stop));
	
		$query = API::send();
		$errors1 = $query['Orders']['checkPreconditions']['results'][0];
		if (!empty($errors1['error']))
			Errors::add($errors1['error']['message']);
		$errors2 = (!empty($query['Orders']['checkUserOrders']['results'][0])) ? $query['Orders']['checkUserOrders']['results'][0] : false;
		if (!empty($errors2['error']))
			Errors::add($errors2['error']['message']);
	
		if (!$errors1 && !$errors2)
			$ask_confirm = true;
	}
	else if (!$cancel) {
		API::add('Orders','executeOrder',array(0,($sell_stop && !$sell_limit) ? $sell_stop_price1 : $sell_price1,$sell_amount1,$currency1,$user_fee_ask,$sell_market_price1,false,false,false,$sell_stop_price1));
		$query = API::send();
		$operations = $query['Orders']['executeOrder']['results'][0];

		if (!empty($operations['error'])) {
			Errors::add($operations['error']['message']);
		}
		else if ($operations['new_order'] > 0) {
		    $_SESSION["buysell_uniq"][time()] = md5(uniqid(mt_rand(),true));
		    if (count($_SESSION["buysell_uniq"]) > 3) {
		    	unset($_SESSION["buysell_uniq"][min(array_keys($_SESSION["buysell_uniq"]))]);
		    }
		    
			Link::redirect('open-orders.php',array('transactions'=>$operations['transactions'],'new_order'=>1));
			exit;
		}
		else {
		    $_SESSION["buysell_uniq"][time()] = md5(uniqid(mt_rand(),true));
		    if (count($_SESSION["buysell_uniq"]) > 3) {
		    	unset($_SESSION["buysell_uniq"][min(array_keys($_SESSION["buysell_uniq"]))]);
		    }
		    
			Link::redirect('transactions.php',array('transactions'=>$operations['transactions']));
			exit;
		}
	}
}

if ($stats['daily_change'] > 0)
	$arrow = '<i id="up_or_down" class="fa fa-caret-up price-green"></i> ';
elseif ($stats['daily_change'] < 0)
	$arrow = '<i id="up_or_down" class="fa fa-caret-down price-red"></i> ';
else
	$arrow = '<i id="up_or_down" class="fa fa-minus"></i> ';

if ($query['Transactions']['get']['results'][0][0]['maker_type'] == 'sell') {
	$arrow1 = '<i id="up_or_down1" class="fa fa-caret-up price-green"></i> ';
	$p_color = 'price-green';
}
elseif ($query['Transactions']['get']['results'][0][0]['maker_type'] == 'buy') {
	$arrow1 = '<i id="up_or_down1" class="fa fa-caret-down price-red"></i> ';
	$p_color = 'price-red';
}
else {
	$arrow1 = '<i id="up_or_down1" class="fa fa-minus"></i> ';
	$p_color = '';
}


$notice = '';
if ($ask_confirm && $sell) {
	if (!$bank_accounts)
		$notice .= '<div class="message-box-wrap">'.str_replace('[currency]',$currency_info['currency'],Lang::string('buy-errors-no-bank-account')).'</div>';
	
	if (($buy_limit && $buy_stop) || ($sell_limit && $sell_stop))
		$notice .= '<div class="message-box-wrap">'.Lang::string('buy-notify-two-orders').'</div>';
}

$page_title = Lang::string('buy-sell');
if (!$bypass) {
	$_SESSION["buysell_uniq"][time()] = md5(uniqid(mt_rand(),true));
	if (count($_SESSION["buysell_uniq"]) > 3) {
		unset($_SESSION["buysell_uniq"][min(array_keys($_SESSION["buysell_uniq"]))]);
	}
	
	include 'includes/head.php';	
?>
<div class="page_title">
	<div class="container">
		<div class="title"><h1><?= $page_title ?></h1></div>
        <div class="pagenation">&nbsp;<a href="index.php"><?= Lang::string('home') ?></a> <i>/</i> <a href="account.php"><?= Lang::string('account') ?></a> <i>/</i> <a href="buy-sell.php"><?= $page_title ?></a></div>
	</div>
</div>
<div class="container">
	<? if (!$ask_confirm) { ?>
	<div class="ts_head">
		<div class="ts_title"><?= Lang::string('ts-live') ?></div>
		<div class="ts_selectors filters">
			<ul class="list_empty">
				<li>
					<label for="crypto_currency"><?= Lang::string('ts-currency') ?></label>
					<select id="crypto_currency">
					<?
					if ($CFG->currencies) {
						foreach ($CFG->currencies as $key => $currency) {
							if (is_numeric($key) || $currency['is_crypto'] != 'Y')
								continue;
							
							echo '<option '.((strtolower($currency['currency']) == $c_currency1) ? 'selected="selected"' : '').' value="'.strtolower($currency['currency']).'">'.$currency['currency'].'</option>';
						}
					}	
					?>
					</select>
				</li>
				<li>
					<label for="fiat_currency"><?= Lang::string('ts-fiat') ?></label>
					<select id="fiat_currency">
					<?
					if ($CFG->currencies) {
						foreach ($CFG->currencies as $key => $currency) {
							if (is_numeric($key) || $currency['is_crypto'] == 'Y')
								continue;
							
							echo '<option '.((strtolower($currency['currency']) == $currency1) ? 'selected="selected"' : '').' value="'.strtolower($currency['currency']).'">'.$currency['currency'].'</option>';
						}
					}	
					?>
					</select>
				</li>
				<div class="clear"></div>
			</ul>
		</div>
		<div class="ts_ticker ticker">
			<div class="contain">
				<div class="scroll">
				<?
				if ($CFG->currencies) {
					foreach ($CFG->currencies as $key => $currency) {
						if (is_numeric($key) || $currency['is_crypto'] == 'Y')
							continue;
				
						$last_price = number_format($stats['last_price'] * ((empty($currency_info) || $currency_info['currency'] == 'USD') ? 1/$currency[$usd_field] : $currency_info[$usd_field] / $currency[$usd_field]),2);
						echo '<a class="'.(($currency_info['id'] == $currency['id']) ? $p_color.' selected' : '').'" href="'.$CFG->self.'?currency='.strtolower($currency['currency']).'"><span class="abbr">'.$currency['currency'].'</span> <span class="price_'.$currency['currency'].'">'.$last_price.'</span></a>';
					}
				}
				?>
				</div>
			</div>
		</div>
		<div class="clear"></div>
	</div>
	<div class="panel panel-default global_stats ts_stats">
		<div class="panel-heading non-mobile">
	       	<div class="one_fifth"><?= Lang::string('home-stats-last-price') ?></div>
	        <div class="one_fifth"><?= Lang::string('home-stats-daily-change') ?></div>
	        <div class="one_fifth"><?= Lang::string('home-stats-days-range') ?></div>
	        <div class="one_fifth"><?= Lang::string('home-stats-todays-open') ?></div>
	        <div class="one_fifth last"><?= Lang::string('home-stats-24h-volume') ?></div>
			<div class="clear"></div>
		</div>
		<div class="panel-body">
			<div class="one_fifth">
	        	<div class="m_head"><?= Lang::string('home-stats-last-price') ?></div>
	        	<p class="stat1 <?= ($query['Transactions']['get']['results'][0][0]['maker_type'] == 'sell') ? 'price-green' : 'price-red' ?>"><?= $arrow1.$currency_info['fa_symbol'].'<span id="stats_last_price">'.number_format($stats['last_price'],2).'</span>'?><small id="stats_last_price_curr"><?= ($query['Transactions']['get']['results'][0][0]['currency'] == $currency_info['id']) ? false : (($query['Transactions']['get']['results'][0][0]['currency1'] == $currency_info['id']) ? false : ' ('.$CFG->currencies[$query['Transactions']['get']['results'][0][0]['currency1']]['currency'].')') ?></small></p>
	        </div>
	        <div class="one_fifth">
	        	<div class="m_head"><?= Lang::string('home-stats-daily-change') ?></div>
	        	<p class="stat1"><?= $arrow.'<span id="stats_daily_change_abs">'.number_format(abs($stats['daily_change']),2).'</span>' ?> <small><?= '<span id="stats_daily_change_perc">'.number_format(abs($stats['daily_change_percent']),2).'</span>%'?></small></p>
	        </div>
	        <div class="one_fifth">
	        	<div class="m_head"><?= Lang::string('home-stats-days-range') ?></div>
	        	<p class="stat1"><?= $currency_info['fa_symbol'].'<span id="stats_min">'.number_format($stats['min'],2).'</span> - <span id="stats_max">'.number_format($stats['max'],2).'</span>' ?></p>
	        </div>
	        <div class="one_fifth">
	        	<div class="m_head"><?= Lang::string('home-stats-todays-open') ?></div>
	        	<p class="stat1"><?= $currency_info['fa_symbol'].'<span id="stats_open">'.number_format($stats['open'],2).'</span>'?></p>
	        </div>
	        <div class="one_fifth last">
	        	<div class="m_head"><?= Lang::string('home-stats-24h-volume') ?></div>
	        	<p class="stat1"><?= '<span id="stats_traded">'.number_format($stats['total_btc_traded'],2).'</span>' ?> BTC</p>
	        </div>
	        <div class="panel-divider"></div>
	        <div class="one_third">
	        	<h5><?= Lang::string('home-stats-market-cap') ?>: <em class="stat2">$<?= '<span id="stats_market_cap">'.number_format($stats['market_cap']).'</span>'?></em></h5>
	        </div>
	        <div class="one_third">
	        	<h5><?= Lang::string('home-stats-total-btc') ?>: <em class="stat2"><?= '<span id="stats_total_btc">'.number_format($stats['total_btc']).'</span>' ?></em></h5>
	        </div>
	        <div class="one_third last">
	        	<h5><?= Lang::string('home-stats-global-volume') ?>: <em class="stat2">$<?= '<span id="stats_trade_volume">'.number_format($stats['trade_volume']).'</span>' ?></em></h5>
	        </div>
	        <div class="clear"></div>
		</div>
	</div>
	<? } ?>
	<? include 'includes/sidebar_ts.php'; ?>
	<div class="content_right">
		<? Errors::display(); ?>
		<?= ($notice) ? '<div class="notice">'.$notice.'</div>' : '' ?>
		<? if (!$ask_confirm) { ?>
		<div class="ts_graphs">
			<div class="graph_tabs">
				<a href="#" data-option="timeline" class="ts_view selected"><i class="fa fa-line-chart"></i> <?= Lang::string('ts-timeline') ?></a>
				<a href="#" data-option="order-book" class="ts_view"><i class="fa fa-area-chart"></i> <?= Lang::string('order-book') ?></a>
				<a href="#" data-option="distribution" class="ts_view"><i class="fa fa-users"></i> <?= Lang::string('ts-distribution') ?></a>
				<div class="clear"></div>
			</div> 
			<div class="graph_options">
				<label for="graph_time"><i class="fa fa-clock-o"></i></label>
	        	<select id="graph_time">
					<option <?= ($_SESSION['timeframe'] == '1min') ? 'selected="selected"' : '' ?> value="1min">1m</a>
					<option <?= ($_SESSION['timeframe'] == '3min') ? 'selected="selected"' : '' ?> value="3min">3m</a>
		        	<option <?= (!$_SESSION['timeframe'] || $_SESSION['timeframe'] == '5min') ? 'selected="selected"' : '' ?> value="5min">5m</a>
		        	<option <?= ($_SESSION['timeframe'] == '15min') ? 'selected="selected"' : '' ?>value="15min">15m</a>
		        	<option <?= ($_SESSION['timeframe'] == '30min') ? 'selected="selected"' : '' ?> value="30min">30m</a>
		        	<option <?= ($_SESSION['timeframe'] == '1h') ? 'selected="selected"' : '' ?> value="1h">1h</a>
		        	<option <?= ($_SESSION['timeframe'] == '2h') ? 'selected="selected"' : '' ?> value="2h">2h</a>
		        	<option <?= ($_SESSION['timeframe'] == '4h') ? 'selected="selected"' : '' ?> value="4h">4h</a>
		        	<option <?= ($_SESSION['timeframe'] == '6h') ? 'selected="selected"' : '' ?> value="6h">6h</a>
		        	<option <?= ($_SESSION['timeframe'] == '12h') ? 'selected="selected"' : '' ?> value="12h">12h</a>
		        	<option <?= ($_SESSION['timeframe'] == '1d') ? 'selected="selected"' : '' ?> value="1d">1d</a>
		        	<option <?= ($_SESSION['timeframe'] == '3d') ? 'selected="selected"' : '' ?> value="3d">3d</a>
		        	<option <?= ($_SESSION['timeframe'] == '1w') ? 'selected="selected"' : '' ?> value="1w">1w</a>
	        	</select>
	        	<div id="graph_over">
	        		<span class="g_over"><b>Open:</b> <span id="g_open"></span></span>
					<span class="g_over"><b>Close:</b> <span id="g_close"></span></span>
					<span class="g_over"><b>High:</b> <span id="g_high"></span></span>
					<span class="g_over"><b>Low:</b> <span id="g_low"></span></span>
					<span class="g_over"><b>Vol:</b> <span id="g_vol"></span></span>
					<div class="repeat-line o1"></div>
		        	<div class="repeat-line o2"></div>
		        	<div class="repeat-line o3"></div>
		        	<div class="repeat-line o4"></div>
		        	<div class="repeat-line o5"></div>
	        		<div class="clear"></div>
	        	</div>
	        	<div class="clear"></div>
	        </div>
	        <div id="ts_timeline" class="graph_contain">
	        	<input type="hidden" id="graph_price_history_currency" value="<?= ($currency1) ? $currency1 : 'usd' ?>" />
	        	<div id="graph_candles"></div>
		        <div class="clear_300"></div>
		        <div class="clear"></div>
		        <div id="graph_price_history"></div>
		        <div class="drag_zoom">
		        	<div class="contain">
			        	<div id="zl" class="handle"></div>
			        	<div id="zr" class="handle"></div>
			        	<div class="bg"></div>
		        	</div>
		        </div>
		        <div class="clear_50"></div>
		        <div class="clear"></div>
	        </div>
	        <div id="ts_order_book" class="graph_contain">
				<input type="hidden" id="graph_orders_currency" value="<?= $currency1 ?>" />
				<div id="graph_orders"></div>
				<div id="tooltip">
					<div class="price"></div>
					<div class="bid"><?= Lang::string('orders-bid') ?> <span></span> BTC</div>
					<div class="ask"><?= Lang::string('orders-ask') ?> <span></span> BTC</div>
				</div>
			</div>
			<div id="ts_distribution" class="graph_contain">
				<div id="graph_distribution"></div>
				<div id="tooltip1">
					<div class="price"><span></span> BTC</div>
					<div class="users"><span></span> <?= Lang::string('ts-users') ?></div>
				</div>
			</div>
		</div>
		<? } ?>
		<div class="testimonials-4">
			<? if (!$ask_confirm) { ?>
			<input type="hidden" id="user_fee" value="<?= $user_fee_both['fee'] ?>" />
			<input type="hidden" id="user_fee1" value="<?= $user_fee_both['fee1'] ?>" />
			<div class="one_half">
				<div class="content">
					<h3 class="section_label">
						<span class="left"><i class="fa fa-btc fa-2x"></i></span>
						<span class="right"><?= Lang::string('buy-bitcoins') ?></span>
					</h3>
					<div class="clear"></div>
					<form id="buy_form" action="buy-sell.php" method="POST">
						<div class="buyform">
							<div class="spacer"></div>
							<div class="calc dotted">
								<div class="label"><?= str_replace('[currency]','<span class="sell_currency_label">'.$currency_info['currency'].'</span>',Lang::string('buy-fiat-available')) ?></div>
								<div class="value"><span class="buy_currency_char"><?= $currency_info['fa_symbol'] ?></span><span id="buy_user_available"><?= ((!empty($user_available[strtoupper($currency1)])) ? number_format($user_available[strtoupper($currency1)],2) : '0.00') ?></span></div>
								<div class="clear"></div>
							</div>
							<div class="spacer"></div>
							<div class="param">
								<label for="buy_amount"><?= Lang::string('buy-amount') ?></label>
								<input name="buy_amount" id="buy_amount" type="text" value="<?= $buy_amount1 ?>" />
								<div class="qualify">BTC</div>
								<div class="clear"></div>
							</div>
							<div class="param">
								<label for="buy_currency"><?= Lang::string('buy-with-currency') ?></label>
								<select id="buy_currency" name="currency">
								<?
								if ($CFG->currencies) {
									foreach ($CFG->currencies as $key => $currency) {
										if (is_numeric($key) || $currency['is_crypto'] == 'Y')
											continue;
										
										echo '<option '.((strtolower($currency['currency']) == $currency1) ? 'selected="selected"' : '').' value="'.strtolower($currency['currency']).'">'.$currency['currency'].'</option>';
									}
								}	
								?>
								</select>
								<div class="clear"></div>
							</div>
							<div class="param lessbottom">
								<input class="checkbox" name="buy_market_price" id="buy_market_price" type="checkbox" value="1" <?= ($buy_market_price1 && !$buy_stop) ? 'checked="checked"' : '' ?> <?= (!$asks) ? 'readonly="readonly"' : '' ?> />
								<label for="buy_market_price"><?= Lang::string('buy-market-price') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<div class="clear"></div>
							</div>
							<div class="param lessbottom">
								<input class="checkbox" name="buy_limit" id="buy_limit" type="checkbox" value="1" <?= ($buy_limit && !$buy_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="buy_limit"><?= Lang::string('buy-limit') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<div class="clear"></div>
							</div>
							<div class="param lessbottom">
								<input class="checkbox" name="buy_stop" id="buy_stop" type="checkbox" value="1" <?= ($buy_stop && !$buy_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="buy_stop"><?= Lang::string('buy-stop') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<div class="clear"></div>
							</div>
							<div id="buy_price_container" class="param" <?= (!$buy_limit && !$buy_market_price1) ? 'style="display:none;"' : '' ?>>
								<label for="buy_price"><span id="buy_price_limit_label" <?= (!$buy_limit) ? 'style="display:none;"' : '' ?>><?= Lang::string('buy-limit-price') ?></span><span id="buy_price_market_label" <?= ($buy_limit) ? 'style="display:none;"' : '' ?>><?= Lang::string('buy-price') ?></span></label>
								<input name="buy_price" id="buy_price" type="text" value="<?= number_format($buy_price1,2) ?>" <?= ($buy_market_price1) ? 'readonly="readonly"' : '' ?> />
								<div class="qualify"><span class="buy_currency_label"><?= $currency_info['currency'] ?></span></div>
								<div class="clear"></div>
							</div>
							<div id="buy_stop_container" class="param" <?= (!$buy_stop) ? 'style="display:none;"' : '' ?>>
								<label for="buy_stop_price"><?= Lang::string('buy-stop-price') ?></label>
								<input name="buy_stop_price" id="buy_stop_price" type="text" value="<?= number_format($buy_stop_price1,2) ?>" />
								<div class="qualify"><span class="buy_currency_label"><?= $currency_info['currency'] ?></span></div>
								<div class="clear"></div>
							</div>
							<div class="spacer"></div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-subtotal') ?></div>
								<div class="value"><span class="buy_currency_char"><?= $currency_info['fa_symbol'] ?></span><span id="buy_subtotal"><?= number_format($buy_subtotal1,2) ?></span></div>
								<div class="clear"></div>
							</div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-fee') ?> <a title="<?= Lang::string('account-view-fee-schedule') ?>" href="fee-schedule.php"><i class="fa fa-question-circle"></i></a></div>
								<div class="value"><span id="buy_user_fee"><?= $user_fee_bid ?></span>%</div>
								<div class="clear"></div>
							</div>
							<div class="calc bigger">
								<div class="label">
									<span id="buy_total_approx_label"><?= str_replace('[currency]','<span class="buy_currency_label">'.$currency_info['currency'].'</span>',Lang::string('buy-total-approx')) ?></span>
									<span id="buy_total_label" style="display:none;"><?= Lang::string('buy-total') ?></span>
								</div>
								<div class="value"><span class="buy_currency_char"><?= $currency_info['fa_symbol'] ?></span><span id="buy_total"><?= number_format($buy_total1,2) ?></span></div>
								<div class="clear"></div>
							</div>
							<input type="hidden" name="buy" value="1" />
							<input type="hidden" name="uniq" value="<?= end($_SESSION["buysell_uniq"]) ?>" />
							<input type="submit" name="submit" value="<?= Lang::string('buy-bitcoins') ?>" class="but_user" />
						</div>
					</form>
				</div>
			</div>
			<div class="one_half last">
				<div class="content">
					<h3 class="section_label">
						<span class="left"><i class="fa fa-money fa-2x"></i></span>
						<span class="right"><?= Lang::string('sell-bitcoins') ?></span>
					</h3>
					<div class="clear"></div>
					<form id="sell_form" action="buy-sell.php" method="POST">
						<div class="buyform">
							<div class="spacer"></div>
							<div class="calc dotted">
								<div class="label"><?= Lang::string('sell-btc-available') ?></div>
								<div class="value"><span id="sell_user_available"><?= number_format($user_available['BTC'],8) ?></span> BTC</div>
								<div class="clear"></div>
							</div>
							<div class="spacer"></div>
							<div class="param">
								<label for="sell_amount"><?= Lang::string('sell-amount') ?></label>
								<input name="sell_amount" id="sell_amount" type="text" value="<?= $sell_amount1 ?>" />
								<div class="qualify">BTC</div>
								<div class="clear"></div>
							</div>
							<div class="param">
								<label for="sell_currency"><?= Lang::string('buy-with-currency') ?></label>
								<select id="sell_currency" name="currency">
								<?
								if ($CFG->currencies) {
									foreach ($CFG->currencies as $key => $currency) {
										if (is_numeric($key) || $currency['is_crypto'] == 'Y')
											continue;
										
										echo '<option '.((strtolower($currency['currency']) == $currency1) ? 'selected="selected"' : '').' value="'.strtolower($currency['currency']).'">'.$currency['currency'].'</option>';
									}
								}	
								?>
								</select>
								<div class="clear"></div>
							</div>
							<div class="param lessbottom">
								<input class="checkbox" name="sell_market_price" id="sell_market_price" type="checkbox" value="1" <?= ($sell_market_price1 && !$sell_stop) ? 'checked="checked"' : '' ?> <?= (!$bids) ? 'readonly="readonly"' : '' ?> />
								<label for="sell_market_price"><?= Lang::string('sell-market-price') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<div class="clear"></div>
							</div>
							<div class="param lessbottom">
								<input class="checkbox" name="sell_limit" id="sell_limit" type="checkbox" value="1" <?= ($sell_limit && !$sell_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="sell_stop"><?= Lang::string('buy-limit') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<div class="clear"></div>
							</div>
							<div class="param lessbottom">
								<input class="checkbox" name="sell_stop" id="sell_stop" type="checkbox" value="1" <?= ($sell_stop && !$sell_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="sell_stop"><?= Lang::string('buy-stop') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<div class="clear"></div>
							</div>
							<div id="sell_price_container" class="param" <?= (!$sell_limit && !$sell_market_price1) ? 'style="display:none;"' : '' ?>>
								<label for="sell_price"><span id="sell_price_limit_label" <?= (!$sell_limit) ? 'style="display:none;"' : '' ?>><?= Lang::string('buy-limit-price') ?></span><span id="sell_price_market_label" <?= ($sell_limit) ? 'style="display:none;"' : '' ?>><?= Lang::string('buy-price') ?></span></label>
								<input name="sell_price" id="sell_price" type="text" value="<?= number_format($sell_price1,2) ?>" <?= ($sell_market_price1) ? 'readonly="readonly"' : '' ?> />
								<div class="qualify"><span class="sell_currency_label"><?= $currency_info['currency'] ?></span></div>
								<div class="clear"></div>
							</div>
							<div id="sell_stop_container" class="param" <?= (!$sell_stop) ? 'style="display:none;"' : '' ?>>
								<label for="sell_stop_price"><?= Lang::string('buy-stop-price') ?></label>
								<input name="sell_stop_price" id="sell_stop_price" type="text" value="<?= number_format($sell_stop_price1,2) ?>" />
								<div class="qualify"><span class="sell_currency_label"><?= $currency_info['currency'] ?></span></div>
								<div class="clear"></div>
							</div>
							<div class="spacer"></div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-subtotal') ?></div>
								<div class="value"><span class="sell_currency_char"><?= $currency_info['fa_symbol'] ?></span><span id="sell_subtotal"><?= number_format($sell_subtotal1,2) ?></span></div>
								<div class="clear"></div>
							</div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-fee') ?> <a title="<?= Lang::string('account-view-fee-schedule') ?>" href="fee-schedule.php"><i class="fa fa-question-circle"></i></a></div>
								<div class="value"><span id="sell_user_fee"><?= $user_fee_ask ?></span>%</div>
								<div class="clear"></div>
							</div>
							<div class="calc bigger">
								<div class="label">
									<span id="sell_total_approx_label"><?= str_replace('[currency]','<span class="sell_currency_label">'.$currency_info['currency'].'</span>',Lang::string('sell-total-approx')) ?></span>
									<span id="sell_total_label" style="display:none;"><?= str_replace('[currency]','<span class="sell_currency_label">'.$currency_info['currency'].'</span>',Lang::string('sell-total')) ?></span>
								</div>
								<div class="value"><span class="sell_currency_char"><?= $currency_info['fa_symbol'] ?></span><span id="sell_total"><?= number_format($sell_total1,2) ?></span></div>
								<div class="clear"></div>
							</div>
							<input type="hidden" name="sell" value="1" />
							<input type="hidden" name="uniq" value="<?= end($_SESSION["buysell_uniq"]) ?>" />
							<input type="submit" name="submit" value="<?= Lang::string('sell-bitcoins') ?>" class="but_user" />
						</div>
					</form>
				</div>
			</div>
			<? } else { ?>
			<div class="one_half last">
				<div class="content">
					<h3 class="section_label">
						<span class="left"><i class="fa fa-exclamation fa-2x"></i></span>
						<span class="right"><?= Lang::string('confirm-transaction') ?></span>
						<div class="clear"></div>
					</h3>
					<div class="clear"></div>
					<form id="confirm_form" action="buy-sell.php" method="POST">
						<input type="hidden" name="confirmed" value="1" />
						<input type="hidden" id="cancel" name="cancel" value="" />
						<? if ($buy) { ?>
						<div class="balances" style="margin-left:0;">
							<div class="label"><?= Lang::string('buy-amount') ?></div>
							<div class="amount"><?= number_format($buy_amount1,8) ?></div>
							<input type="hidden" name="buy_amount" value="<?= $buy_amount1 ?>" />
							<div class="label"><?= Lang::string('buy-with-currency') ?></div>
							<div class="amount"><?= $currency_info['currency'] ?></div>
							<input type="hidden" name="buy_currency" value="<?= $currency1 ?>" />
							<? if ($buy_limit || $buy_market_price1) { ?>
							<div class="label"><?= ($buy_market_price1) ? Lang::string('buy-price') : Lang::string('buy-limit-price') ?></div>
							<div class="amount"><?= number_format($buy_price1,2) ?></div>
							<input type="hidden" name="buy_price" value="<?= $buy_price1 ?>" />
							<? } ?>
							<? if ($buy_stop) { ?>
							<div class="label"><?= Lang::string('buy-stop-price') ?></div>
							<div class="amount"><?= number_format($buy_stop_price1,2) ?></div>
							<input type="hidden" name="buy_stop_price" value="<?= $buy_stop_price1 ?>" />
							<? } ?>
						</div>
						<div class="buyform">
							<? if ($buy_market_price1) { ?>
							<div class="mar_top1"></div>
							<div class="param lessbottom">
								<input disabled="disabled" class="checkbox" name="dummy" id="buy_market_price" type="checkbox" value="1" <?= ($buy_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="buy_market_price"><?= Lang::string('buy-market-price') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<input type="hidden" name="buy_market_price" value="<?= $buy_market_price1 ?>" />
								<div class="clear"></div>
							</div>
							<? } ?>
							<? if ($buy_limit) { ?>
							<div class="mar_top1"></div>
							<div class="param lessbottom">
								<input disabled="disabled" class="checkbox" name="dummy" id="buy_limit" type="checkbox" value="1" <?= ($buy_limit && !$buy_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="buy_limit"><?= Lang::string('buy-limit') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<input type="hidden" name="buy_limit" value="<?= $buy_limit ?>" />
								<div class="clear"></div>
							</div>
							<? } ?>
							<? if ($buy_stop) { ?>
							<div class="mar_top1"></div>
							<div class="param lessbottom">
								<input disabled="disabled" class="checkbox" name="dummy" id="buy_stop" type="checkbox" value="1" <?= ($buy_stop && !$buy_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="buy_stop"><?= Lang::string('buy-stop') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<input type="hidden" name="buy_stop" value="<?= $buy_stop ?>" />
								<div class="clear"></div>
							</div>
							<? } ?>
							<div class="spacer"></div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-subtotal') ?></div>
								<div class="value"><span class="sell_currency_char"><?= $currency_info['fa_symbol'] ?></span><?= number_format($buy_subtotal1,2) ?></div>
								<div class="clear"></div>
							</div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-fee') ?> <a title="<?= Lang::string('account-view-fee-schedule') ?>" href="fee-schedule.php"><i class="fa fa-question-circle"></i></a></div>
								<div class="value"><span id="sell_user_fee"><?= $user_fee_bid ?></span>%</div>
								<div class="clear"></div>
							</div>
							<div class="calc bigger">
								<div class="label">
									<span id="buy_total_approx_label"><?= str_replace('[currency]','<span class="buy_currency_label">'.$currency_info['currency'].'</span>',Lang::string('buy-total-approx')) ?></span>
									<span id="buy_total_label" style="display:none;"><?= Lang::string('buy-total') ?></span>
								</div>
								
								<div class="value"><span class="buy_currency_char"><?= $currency_info['fa_symbol'] ?></span><span id="buy_total"><?= number_format($buy_total1,2) ?></span></div>
								<div class="clear"></div>
							</div>
							<input type="hidden" name="buy" value="1" />
							<input type="hidden" name="uniq" value="<?= end($_SESSION["buysell_uniq"]) ?>" />
						</div>
						<ul class="list_empty">
							<li style="margin-bottom:0;"><input type="submit" name="submit" value="<?= Lang::string('confirm-buy') ?>" class="but_user" /></li>
							<li style="margin-bottom:0;"><input id="cancel_transaction" type="submit" name="dont" value="<?= Lang::string('confirm-back') ?>" class="but_user grey" /></li>
						</ul>
						<div class="clear"></div>
						<? } else { ?>
						<div class="balances" style="margin-left:0;">
							<div class="label"><?= Lang::string('sell-amount') ?></div>
							<div class="amount"><?= number_format($sell_amount1,8) ?></div>
							<input type="hidden" name="sell_amount" value="<?= $sell_amount1 ?>" />
							<div class="label"><?= Lang::string('buy-with-currency') ?></div>
							<div class="amount"><?= $currency_info['currency'] ?></div>
							<input type="hidden" name="sell_currency" value="<?= $currency1 ?>" />
							<? if ($sell_limit || $sell_market_price1) { ?>
							<div class="label"><?= ($sell_market_price1) ? Lang::string('buy-price') : Lang::string('buy-limit-price') ?></div>
							<div class="amount"><?= number_format($sell_price1,2) ?></div>
							<input type="hidden" name="sell_price" value="<?= $sell_price1 ?>" />
							<? } ?>
							<? if ($sell_stop) { ?>
							<div class="label"><?= Lang::string('buy-stop-price') ?></div>
							<div class="amount"><?= number_format($sell_stop_price1,2) ?></div>
							<input type="hidden" name="sell_stop_price" value="<?= $sell_stop_price1 ?>" />
							<? } ?>
						</div>
						<div class="buyform">
							<? if ($sell_market_price1) { ?>
							<div class="mar_top1"></div>
							<div class="param lessbottom">
								<input disabled="disabled" class="checkbox" name="dummy" id="sell_market_price" type="checkbox" value="1" <?= ($sell_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="sell_market_price"><?= Lang::string('sell-market-price') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<input type="hidden" name="sell_market_price" value="<?= $sell_market_price1 ?>" />
								<div class="clear"></div>
							</div>
							<? } ?>
							<? if ($sell_limit) { ?>
							<div class="mar_top1"></div>
							<div class="param lessbottom">
								<input disabled="disabled" class="checkbox" name="dummy" id="sell_limit" type="checkbox" value="1" <?= ($sell_limit && !$sell_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="sell_limit"><?= Lang::string('buy-limit') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<input type="hidden" name="sell_limit" value="<?= $sell_limit ?>" />
								<div class="clear"></div>
							</div>
							<? } ?>
							<? if ($sell_stop) { ?>
							<div class="mar_top1"></div>
							<div class="param lessbottom">
								<input disabled="disabled" class="checkbox" name="dummy" id="sell_stop" type="checkbox" value="1" <?= ($sell_stop && !$sell_market_price1) ? 'checked="checked"' : '' ?> />
								<label for="sell_stop"><?= Lang::string('buy-stop') ?> <a title="<?= Lang::string('buy-market-rates-info') ?>" href="help.php"><i class="fa fa-question-circle"></i></a></label>
								<input type="hidden" name="sell_stop" value="<?= $sell_stop ?>" />
								<div class="clear"></div>
							</div>
							<? } ?>
							<div class="spacer"></div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-subtotal') ?></div>
								<div class="value"><span class="sell_currency_char"><?= $currency_info['fa_symbol'] ?></span><?= number_format($sell_subtotal1,2) ?></div>
								<div class="clear"></div>
							</div>
							<div class="calc">
								<div class="label"><?= Lang::string('buy-fee') ?> <a title="<?= Lang::string('account-view-fee-schedule') ?>" href="fee-schedule.php"><i class="fa fa-question-circle"></i></a></div>
								<div class="value"><span id="sell_user_fee"><?= $user_fee_ask ?></span>%</div>
								<div class="clear"></div>
							</div>
							<div class="calc bigger">
								<div class="label">
									<span id="sell_total_approx_label"><?= str_replace('[currency]','<span class="sell_currency_label">'.$currency_info['currency'].'</span>',Lang::string('sell-total-approx')) ?></span>
									<span id="sell_total_label" style="display:none;"><?= str_replace('[currency]','<span class="sell_currency_label">'.$currency_info['currency'].'</span>',Lang::string('sell-total')) ?></span>
								</div>
								<div class="value"><span class="sell_currency_char"><?= $currency_info['fa_symbol'] ?></span><span id="sell_total"><?= number_format($sell_total1,2) ?></span></div>
								<div class="clear"></div>
							</div>
							<input type="hidden" name="sell" value="1" />
							<input type="hidden" name="uniq" value="<?= end($_SESSION["buysell_uniq"]) ?>" />
						</div>
						<ul class="list_empty">
							<li style="margin-bottom:0;"><input type="submit" name="submit" value="<?= Lang::string('confirm-sale') ?>" class="but_user" /></li>
							<li style="margin-bottom:0;"><input id="cancel_transaction" type="submit" name="dont" value="<?= Lang::string('confirm-back') ?>" class="but_user grey" /></li>
						</ul>
						<div class="clear"></div>
						<? } ?>
					</form>
				</div>
			</div>
			<? } ?>
		</div>
		<div class="mar_top3"></div>
		<div class="clear"></div>
		<div id="filters_area">
<? } ?>
			<? if (!$ask_confirm) { ?>
			<div class="one_half">
				<h3><?= Lang::string('orders-bid-top-10') ?></h3>
	        	<div class="table-style">
	        		<table class="table-list trades" id="bids_list">
	        			<tr>
	        				<th><?= Lang::string('orders-price') ?></th>
	        				<th><?= Lang::string('orders-amount') ?></th>
	        				<th><?= Lang::string('orders-value') ?></th>
	        			</tr>
	        			<? 
	        			if ($bids) {
							foreach ($bids as $bid) {
								$mine = (!empty(User::$info['user']) && $bid['user_id'] == User::$info['user'] && $bid['btc_price'] == $bid['fiat_price']) ? '<a class="fa fa-user" href="open-orders.php?id='.$bid['id'].'" title="'.Lang::string('home-your-order').'"></a>' : '';
								echo '
						<tr id="bid_'.$bid['id'].'" class="bid_tr">
							<td>'.$mine.$currency_info['fa_symbol'].'<span class="order_price">'.number_format($bid['btc_price'],2).'</span> '.(($bid['btc_price'] != $bid['fiat_price']) ? '<a title="'.str_replace('[currency]',$CFG->currencies[$bid['currency']]['currency'],Lang::string('orders-converted-from')).'" class="fa fa-exchange" href="" onclick="return false;"></a>' : '').'</td>
							<td><span class="order_amount">'.number_format($bid['btc'],8).'</span></td>
							<td>'.$currency_info['fa_symbol'].'<span class="order_value">'.number_format(($bid['btc_price'] * $bid['btc']),2).'</span></td>
						</tr>';
							}
						}
						echo '<tr id="no_bids" style="'.(is_array($bids) ? 'display:none;' : '').'"><td colspan="4">'.Lang::string('orders-no-bid').'</td></tr>';
	        			?>
	        		</table>
				</div>
			</div>
			<div class="one_half last">
				<h3><?= Lang::string('orders-ask-top-10') ?></h3>
				<div class="table-style">
					<table class="table-list trades" id="asks_list">
						<tr>
							<th><?= Lang::string('orders-price') ?></th>
	        				<th><?= Lang::string('orders-amount') ?></th>
	        				<th><?= Lang::string('orders-value') ?></th>
						</tr>
	        			<? 
	        			if ($asks) {
							foreach ($asks as $ask) {
								$mine = (!empty(User::$info['user']) && $ask['user_id'] == User::$info['user'] && $ask['btc_price'] == $ask['fiat_price']) ? '<a class="fa fa-user" href="open-orders.php?id='.$ask['id'].'" title="'.Lang::string('home-your-order').'"></a>' : '';
								echo '
						<tr id="ask_'.$ask['id'].'" class="ask_tr">
							<td>'.$mine.$currency_info['fa_symbol'].'<span class="order_price">'.number_format($ask['btc_price'],2).'</span> '.(($ask['btc_price'] != $ask['fiat_price']) ? '<a title="'.str_replace('[currency]',$CFG->currencies[$ask['currency']]['currency'],Lang::string('orders-converted-from')).'" class="fa fa-exchange" href="" onclick="return false;"></a>' : '').'</td>
							<td><span class="order_amount">'.number_format($ask['btc'],8).'</span></td>
							<td>'.$currency_info['fa_symbol'].'<span class="order_value">'.number_format(($ask['btc_price'] * $ask['btc']),2).'</span></td>
						</tr>';
							}
						}
						echo '<tr id="no_asks" style="'.(is_array($asks) ? 'display:none;' : '').'"><td colspan="4">'.Lang::string('orders-no-ask').'</td></tr>';
	        			?>
					</table>
				</div>
				<div class="clear"></div>
			</div>
			<? } ?>
<? if (!$bypass) { ?>
		</div>
		<div class="mar_top5"></div>
	</div>
</div>
<? include 'includes/foot.php'; ?>
<? } ?>
