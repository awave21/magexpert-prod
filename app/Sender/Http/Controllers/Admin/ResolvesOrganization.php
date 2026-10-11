<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Models\Organization;
use App\Sender\Models\User;
use Illuminate\Http\Request;

trait ResolvesOrganization
{
    private function organization(Request $request): Organization
    {
        return $request->attributes->get('sender_organization');
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('sender_user');
    }
}
