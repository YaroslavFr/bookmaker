<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderBy('id', 'desc')->limit(50)->get();
        return view('admin.index', ['users' => $users]);
    }

    public function create()
    {
        return view('admin.create_user');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => ['required','string','min:3','max:50','alpha_dash','unique:users,username'],
            'name' => ['required','string','min:3','max:100'],
            'password' => ['required','string','min:6'],
            'role' => ['required','in:admin,moderator,user'],
        ]);
        $email = $request->string('email')->toString();
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $email ?: ($data['username'].'@local'),
            'password' => $data['password'],
            'role' => $data['role'],
        ]);
        return redirect()->route('admin.index');
    }
}