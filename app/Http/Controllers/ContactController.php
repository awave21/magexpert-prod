<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ContactController extends Controller
{
    /**
     * Отображение страницы контактов
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        return Inertia::render('Contacts');
    }
}