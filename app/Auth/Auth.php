<?php

/**
 * This file is part of sleeti.
 * Copyright (C) 2016  Eliot Partridge
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Sleeti\Auth;

use Sleeti\Models\User;
use Sleeti\Models\UserPermissions;
use Sleeti\Models\UserSettings;
use Sleeti\Models\UserRememberToken;

/**
 * General auth handler class
 *
 * Handles sleeti user authentication
 */
class Auth
{
	const REMEMBER_ME_TOKEN_DELIMITER = '__|__';

	protected $container;

	public function __construct($container) {
		$this->container = $container;
	}

	/**
	 * Gets the currently authenticated user
	 * @return \Sleeti\Models\User The currently authenticated user (null if no user is authenticated)
	 */
	public function user() {
		$user = isset($_SESSION['user']) ? User::find($_SESSION['user']) : null;
		return $user;
	}

	/**
	 * Determines if there is a user ID set in the current session
	 * @return boolean Is a user currently authenticated?
	 */
	public function check() {
		return isset($_SESSION['user']) && !isset($_SESSION['tfa-partial']);
	}

	/**
	 * Attempt user authentication with a given identifier and password
	 * @param  string $identifier The user's identifier (email or username)
	 * @param  string $password   The user's password
	 * @return \Sleeti\Models\User  The User matching the given credentials (false if no user found)
	 */
	public function attempt($identifier, $password, $remember = false) {
		$user = User::where('email', $identifier)->orWhere('username', $identifier)->first();

		// If there's no User with the given email or username, there's nothing to do
		if (!$user) {
			return false;
		}

		if (password_verify($password, $user->password)) {
			$_SESSION['user'] = $user->id;

			// Lazy password rehash in case settings or algo changes
			if (password_needs_rehash($user->password, PASSWORD_DEFAULT, ['cost' => ($this->container['settings']['password']['cost'] ?? 10)])) {
				$user->password = password_hash($password, PASSWORD_DEFAULT, ['cost' => ($this->container['settings']['password']['cost'] ?? 10)]);
				$user->save();

				$this->container->log->log('auth', \Monolog\Logger::DEBUG, 'User\'s password was rehashed.', [
					'id'       => $user->id,
					'username' => $user->username,
				]);
			}

			// Just in case there isn't an associated UserPermission for this User, create one
			if ($user->permissions === null) {
				$userPerms = UserPermissions::create([
					'user_id' => $user->id,
					'flags'   => '',
				]);

				$this->container->log->log('auth', \Monolog\Logger::DEBUG, 'User permissions record created.', [
					$user->id,
					$user->username,
				]);
			}

			// Same for UserSettings
			if ($user->settings === null) {
				$this->container->log->log('auth', \Monolog\Logger::DEBUG, 'User settings record created.', [
					$user->id,
					$user->username,
				]);

				$userSettings = UserSettings::create([
					'user_id' => $user->id,
				]);
			}

			$this->container->log->log('auth', \Monolog\Logger::INFO, 'User logged in.', [
				$user->id,
				$user->username,
			]);

			return true;
		}

		return false;
	}

	public function getRememberCredentialsFromCookie() {
		if (!isset($_COOKIE['remember_me']) || empty($_COOKIE['remember_me'])) return null;

		$cookie = $_COOKIE['remember_me'];

		$parts = explode($this::REMEMBER_ME_TOKEN_DELIMITER, $cookie);

		if (!isset($parts[0]) || !isset($parts[1])) {
			$this->removeRememberCookie();
			return null;
		}

		return $parts;
	}

	/**
	 * Attempts to authenticate a user with their remember token
	 */
	public function attemptRemember() {
		if ($this->check()) return;

		$parts = $this->getRememberCredentialsFromCookie();

		if (!$parts) {
			$this->removeRememberCookie();
			return;
		}

		$tokens = UserRememberToken::where('identifier', $parts[0])->get();

		if ($tokens->count() <= 0) {
			$this->removeRememberCookie();
			return;
		}

		$tokenHash = hash('sha384', $parts[1]);

		foreach ($tokens as $token) {
			if (hash_equals($token->token, $tokenHash) && strtotime($token->expires) > time()) {
				$_SESSION['user'] = $token->user_id;

				$user = $this->user();

				$this->container->log->log('auth', \Monolog\Logger::INFO, 'User logged in with remember credentials.', [
					$user->id,
					$user->username,
				]);

				// Regenerate remember_me token on successful remember
				$newToken = $this->container->randomlib->generateString(255);

				$token->token = hash('sha384', $newToken);
				$token->save();

				setcookie(
					"remember_me",
					$token->identifier . $this::REMEMBER_ME_TOKEN_DELIMITER . $newToken,
					strtotime($token->expires),
					'/',
					'',
					isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on',
					true
				);

				return;
			}
		}

		// Invalidate user's (forged?) remember_me cookie
		$this->removeRememberCookie();

		$this->container->log->log('auth', \Monolog\Logger::WARNING, 'User attempted to log in with invalid remember credentials.', [
			$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
			$_SERVER['REMOTE_ADDR'],
		]);
	}

	public function updateRememberCredentials() {
		if (!$this->check()) return;

		$user = $this->user();

		$rand = $this->container->randomlib;

		$rememberIdentifier = $rand->generateString(255);
		$rememberToken      = $rand->generateString(255);
		$rememberTokenHash  = hash('sha384', $rememberToken);

		UserRememberToken::create([
			'user_id'    => $user->id,
			'identifier' => $rememberIdentifier,
			'token'      => $rememberTokenHash,
			'expires'    => date('Y-m-d H:i:s', strtotime('+30 days')),
		]);

		setcookie(
			"remember_me",
			$rememberIdentifier . $this::REMEMBER_ME_TOKEN_DELIMITER . $rememberToken,
			strtotime('+30 days'),
			'/',
			'',
			isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on',
			true
		);
	}

	public function removeRememberCredentials() {
		$parts = $this->getRememberCredentialsFromCookie();
		if (!$parts) return;

		$tokens = UserRememberToken::where('identifier', $parts[0])->get();
		if ($tokens->count() == 0) return;

		foreach ($tokens as $token) {
			if (hash_equals($token->token, hash('sha384', $parts[1]))) {
				$token->delete();
				break;
			}
		}
	}

	public function removeRememberCookie() {
		setcookie(
			"remember_me",
			false,
			1,
			'/',
			'',
			isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on',
			true
		);
	}

	/**
	 * Deauthenticate the current user
	 */
	public function signout() {
		$user = $this->user();

		// Actually log the user out
		unset($_SESSION['user']);

		// Invalidate user's remember_me credentials
		$this->removeRememberCredentials();
		$this->removeRememberCookie();

		// Fully destroy session data in case session.use_strict_mode is 0
		// Borrowed from eeti2 - thanks, Alex :^)
		$params = session_get_cookie_params();
		setcookie(session_name(), '', 1, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
		session_destroy();

		$this->container->log->log('auth', \Monolog\Logger::INFO, 'User logged out.', [
			$user->id,
			$user->username,
		]);
	}
}
