<?php

namespace Eeti\Controllers\Auth;

use Eeti\Controllers\Controller;
use Eeti\Models\User;
use Eeti\Models\UserPermission;
use Respect\Validation\Validator as v;

class AuthController extends Controller
{
	public function getSignOut($request, $response) {
		$this->container->auth->signout();

		$this->container->flash->addMessage('info', 'You have been logged out.');
		return $response->withRedirect($this->container->router->pathFor('home'));
	}

	public function getSignIn($request, $response) {
		return $this->container->view->render($response, 'auth/signin.twig');
	}

	public function postSignIn($request, $response) {
		$auth = $this->container->auth->attempt(
			$request->getParam('identifier'),
			$request->getParam('password')
		);

		if (!$auth) {
			$this->container->flash->addMessage('danger', '<b>Uh oh!</b> We couldn\'t find an account with those details.');
			return $response->withRedirect($this->container->router->pathFor('auth.signin'));
		}

		$this->container->flash->addMessage('success', '<b>Success!</b> Welcome back!');
		return $response->withRedirect($this->container->router->pathFor('home'));
	}

	public function getSignUp($request, $response) {
		return $this->container->view->render($response, 'auth/signup.twig');
	}

	public function postSignUp($request, $response) {
		$validation = $this->container->validator->validate($request, [
			'email'            => v::notEmpty()->noWhitespace()->email()->emailAvailable(),
			'username'         => v::notEmpty()->alnum('-_')->noWhitespace()->usernameAvailable(),
			'password'         => v::notEmpty(),
			'password_confirm' => v::passwordConfirmation($request->getParam('password')),
		]);

		if ($validation->failed()) {
			$this->container->flash->addMessage('danger', '<b>Whoops!</b> Looks like something went wrong.');
			return $response->withRedirect($this->container->router->pathFor('auth.signup'));
		}

		$user = User::create([
			'email'    => $request->getParam('email'),
			'username' => $request->getParam('username'),
			'password' => password_hash($request->getParam('password'), PASSWORD_DEFAULT, $this->container['settings']['password'] ?? ['cost' => 10]),
		]);

		$userPerms = UserPermission::create([
			'user_id' => $user->id,
			'flags'   => '',
		]);

		$userPerms->user()->associate($user);

		$this->container->flash->addMessage('success', '<b>Success!</b> Welcome to ' . $this->container->settings['site']['title'] ?? 'eeti slim' . '!');

		if ($user->id === 1) { // if this is the only user, give them admin
			$user->addPermission('A');
			$this->container->flash->addMessage('info', 'New administrative account created!');
		}

		$this->container->auth->attempt(
			$request->getParam('email'),
			$request->getParam('password')
		);

		return $response->withRedirect($this->container->router->pathFor('home'));
	}

	public function deleteAccount($request, $response, $args) {
		if ($this->container->auth->user()->id !== $args['id'] && !$this->container->auth->user()->isAdmin()) {
			$this->container->flash->addMessage('danger', '<b>Hey!</b> What do you think you\'re doing?! You can\'t delete someone else\'s account!');
			return $response->withRedirect($this->container->router->pathFor('home'));
		}

		if (User::where('id', $args['id'])->count() === 0) {
			throw new \Slim\Exception\NotFoundException($request, $response);
		}

		User::where('id', $args['id'])->delete();

		return $response;
	}
}
