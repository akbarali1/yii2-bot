<?php

use yii\debug\Module;

$params = require __DIR__.'/params.php';
$db     = require __DIR__.'/db.php';
if (file_exists(__DIR__.'/../.env')) {
	$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/..');
	$dotenv->load();
}
$config = [
	'id'         => 'basic',
	'basePath'   => dirname(__DIR__),
	'bootstrap'  => ['log'],
	'aliases'    => [
		'@bower' => '@vendor/bower-asset',
		'@npm'   => '@vendor/npm-asset',
	],
	'components' => [
		'request'      => [
			// !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
			'cookieValidationKey' => 'sNEEsIz7C-Cptb8VcsanHNbAy7NzjPWo',
		],
		'cache'        => [
			'class' => 'yii\caching\FileCache',
		],
		'user'         => [
			'identityClass'   => 'app\models\User',
			'enableAutoLogin' => true,
		],
		'errorHandler' => [
			'errorAction' => 'site/error',
		],
		'mailer'       => [
			'class'            => \yii\symfonymailer\Mailer::class,
			'viewPath'         => '@app/mail',
			// send all mails to a file by default.
			'useFileTransport' => true,
		],
		'log'          => [
			'traceLevel' => YII_DEBUG ? 3 : 0,
			'targets'    => [
				[
					'class'  => 'yii\log\FileTarget',
					'levels' => ['error', 'warning'],
				],
			],
		],
		'db'           => $db,
		'urlManager'   => [
			'enablePrettyUrl'     => true,
			'showScriptName'      => false,
			'enableStrictParsing' => false,
			'rules'               => [
				'telegram-bot/webhook'        => 'telegram-bot/webhook',
				'telegram-bot/set-webhook'    => 'telegram-bot/set-webhook',
				'telegram-bot/webhook-info'   => 'telegram-bot/webhook-info',
				'telegram-bot/delete-webhook' => 'telegram-bot/delete-webhook',
			],
		],
	],
	'params'     => $params,
];

if (YII_ENV_DEV) {
	// configuration adjustments for 'dev' environment
	$config['bootstrap'][]      = 'debug';
	$config['modules']['debug'] = [
		'class' => Module::class,
		// uncomment the following to add your IP if you are not connecting from localhost.
		//'allowedIPs' => ['127.0.0.1', '::1'],
	];
	
	$config['bootstrap'][]    = 'gii';
	$config['modules']['gii'] = [
		'class' => \yii\gii\Module::class,
		// uncomment the following to add your IP if you are not connecting from localhost.
		//'allowedIPs' => ['127.0.0.1', '::1'],
	];
}

return $config;
