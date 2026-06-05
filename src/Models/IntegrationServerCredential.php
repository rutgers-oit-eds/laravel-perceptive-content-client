<?php

namespace Rutgers\PerceptiveClient\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationServerCredential extends Model
{
    protected $table = 'pc_is_credentials';
    protected $guarded = [];
    protected $hidden = ['password'];

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = encrypt($value);
    }
}
