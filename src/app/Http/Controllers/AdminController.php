<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderBy('id', 'asc')->limit(50)->get();
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

    public function updateBalance(User $user, Request $request)
    {
        $actor = Auth::user();
        if (!$actor || strtolower((string)($actor->role ?? '')) !== 'admin') {
            abort(403);
        }

        $data = $request->validate([
            'balance' => ['required','numeric','min:0'],
        ]);

        $user->balance = (float) $data['balance'];
        $user->save();

        return redirect()->route('admin.index');
    }

    public function update(User $user, Request $request)
    {
        $actor = Auth::user();
        if (!$actor || strtolower((string)($actor->role ?? '')) !== 'admin') {
            abort(403);
        }

        $data = $request->validate([
            'email' => ['nullable','email', Rule::unique('users','email')->ignore($user->id)],
            'password' => ['nullable','string','min:6'],
            'balance' => ['required','numeric','min:0'],
        ]);

        if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== $user->email) {
            $user->email = (string) $data['email'];
        }
        if (!empty($data['password'])) {
            $user->password = (string) $data['password'];
        }
        $user->balance = (float) $data['balance'];
        $user->save();

        return redirect()->route('admin.index');
    }
}