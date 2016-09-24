<?php

namespace Sleeti\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * User Permissions model, one-to-one with User
 */
class UserPermission extends Model
{
	protected $table = 'user_permissions';

	protected $fillable = [
		'user_id',
		'flags'
	];

	public function user() {
		return $this->belongsTo('Sleeti\\Models\\User', 'user_id', 'id');
	}

	public function contains(string $flag) {
		return strpos($this->flags, $flag) !== false;
	}
}
