<?php

use Sleeti\Middleware\AuthMiddleware;
use Sleeti\Middleware\GuestMiddleware;
use Sleeti\Middleware\TesterMiddleware;
use Sleeti\Middleware\ModeratorMiddleware;
use Sleeti\Middleware\AdminMiddleware;
use Sleeti\Middleware\CsrfViewMiddleware;
use Sleeti\Middleware\NotInstalledMiddleware;

// ugly af grouping
$app->group('', function() use ($container) { // it's groups all the way down
	$this->group('', function() use ($container) {
		$this->get('/', 'HomeController:index')->setName('home');

		$this->get('/user/{id}', 'ProfileController:viewProfile')->setName('user.profile');

		$this->get('/viewfile/{filename}', 'FileController:viewFile')->setName('file.view');

		$this->get('/community', 'CommunityController:community')->setName('community');

		$this->group('', function() use ($container) {
			$this->get('/auth/signup', 'AuthController:getSignUp')->setName('auth.signup');
			$this->post('/auth/signup', 'AuthController:postSignUp');

			$this->get('/auth/signin', 'AuthController:getSignIn')->setName('auth.signin');
			$this->post('/auth/signin', 'AuthController:postSignIn');
		})->add(new GuestMiddleware($container));

		$this->group('', function() use ($container) {
			$this->get('/auth/signout', 'AuthController:getSignOut')->setName('auth.signout');

			$this->get('/auth/password/change', 'PasswordController:getChangePassword')->setName('auth.password.change');
			$this->post('/auth/password/change', 'PasswordController:postChangePassword');

			$this->get('/editprofile', 'ProfileController:getEditProfile')->setName('user.profile.edit');
			$this->post('/editprofile', 'ProfileController:postEditoProfile');

			$this->group('/upload', function() {
				$this->get('', 'FileController:getUpload')->setName('file.upload');
				$this->post('', 'FileController:postUpload');

				$this->get('/paste', 'FileController:getPaste')->setName('file.upload.paste');
				$this->post('/paste', 'FileController:postPaste');

				$this->get('/sharex', 'FileController:getSharex')->setName('file.upload.sharex');
			});

			$this->get('/delete/{filename}', 'FileController:deleteFile')->setName('file.delete');

			$this->group('/admin', function() use ($container) {
				$this->group('/acp', function() {
					$this->get('', 'AcpController:getAcpHome')->setName('admin.acp.home');

					$this->get('/database', 'AcpController:getDatabaseSettings')->setName('admin.acp.database');
					$this->post('/database', 'AcpController:postDatabaseSettings');

					$this->get('/site', 'AcpController:getSiteSettings')->setName('admin.acp.site');
					$this->post('/site', 'AcpController:postSiteSettings');

					$this->get('/password', 'AcpController:getPasswordSettings')->setName('admin.acp.password');
					$this->post('/password', 'AcpController:postPasswordSettings');

					$this->get('/errors', 'AcpController:getErrorSettings')->setName('admin.acp.errors');
					$this->post('/errors', 'AcpController:postErrorSettings');
				});

				$this->group('/user', function() {
					$this->get('/giveperms/{uid}', 'AdminController:getAddPermissionsPage')->setName('admin.user.giveperms');
					$this->post('/giveperms/{uid}', 'AdminController:postAddPermissionsPage');
				});
			})->add(new AdminMiddleware($container));

			$this->group('/mod', function() use ($container) {
				$this->group('/mcp', function() {
					$this->get('', 'McpController:getMcpHome')->setName('mod.mcp.home');
					$this->get('/files', 'McpController:getFiles')->setName('mod.mcp.files');
				});
			})->add(new ModeratorMiddleware($container));
		})->add(new AuthMiddleware($container));

		$this->group('', function() use ($container) {
			$this->get('/install', 'InstallController:getInstall')->setName('install');
			$this->post('/install', 'InstallController:postInstall');
		})->add(new NotInstalledMiddleware($container));
	})->add(new CsrfViewMiddleware($container));
})->add($container['csrf']);

// No CSRF protection for ShareX uploads
// TODO: upload tokens instead of user creds
$app->post('/upload/sharex', 'FileController:sharexUpload');
