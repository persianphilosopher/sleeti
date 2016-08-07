<?php

namespace Eeti\Middleware;

class ModeratorMiddleware extends Middleware
{
	public function __invoke($request, $response, $next) {
		if (!$this->container->auth->user()->isModerator()) {
			$this->container->flash->addMessage('danger', '<b>Hey!</b> Only moderators and admins are allowed there!');
			return $response->withRedirect($this->container->router->pathFor('home'));
		}

		$response = $next($request, $response);
		return $response;
	}
}