<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSettings extends Model
{
    protected $table = "site_settings";
    protected $fillable = [
        "instagram",
        "meta",
        "youtube",
        "competition_details"
    ];
}
