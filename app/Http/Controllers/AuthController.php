<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Services\IntsecClient;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('login');
    }

    public function login(Request $request, IntsecClient $intsec)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Get the authenticated user
            $user = Auth::user();

            // Update last login timestamp
            $user->update(['last_login_at' => now()]);
            $this->report($intsec, $request, 'login_success', 'Successful authentication.', ['role' => $user->role]);

            // Redirect based on user role
            if ($user->role === 'admin') {
                return redirect()->intended('/dashboard');
            } elseif ($user->role === 'receptionist') {
                return redirect()->intended('/receptionist/dashboard');
            }

            // Default redirect for other roles
            return redirect()->intended('/dashboard');
        }

        $this->report($intsec, $request, 'login_failed', 'Failed authentication attempt.');
        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function showRegister()
    {
        return view('register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:6'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'staff', // Default role for new registrations
        ]);

        Auth::login($user);

        return redirect('/dashboard');
    }

    public function logout(Request $request, IntsecClient $intsec)
    {
        $this->report($intsec, $request, 'logout', 'User logout.', ['role' => $request->user()?->role]);
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function report(IntsecClient $intsec, Request $request, string $eventType, string $message, array $metadata = []): void
    {
        $intsec->sendSecurityEvent([
            'event_type' => $eventType, 'ip' => $request->ip(), 'route' => '/'.$request->path(), 'method' => $request->method(),
            'user_agent' => $request->userAgent(), 'message' => $message, 'metadata' => $metadata,
        ]);
    }
}
