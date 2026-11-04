<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminMfa;
use Illuminate\Http\Request;

final class AdminAuthController extends Controller
{
    public function __construct(private AdminMfa $mfa) {}
    private function result(Request $r, array $data = []) { return response()->json($data + ['csrf' => $r->session()->token()], 200, ['Cache-Control' => 'no-store, private']); }
    public function csrf(Request $r) { return $this->result($r); }
    public function login(Request $r) {
        $v = $r->validate(['email' => 'required|string|email|max:255', 'password' => 'required|string|max:128']);
        $this->mfa->login($r, $v['email'], $v['password']); return $this->result($r, ['challenge' => true]);
    }
    public function enrollment(Request $r) { return $this->result($r, ['secret' => $this->mfa->enrollment($r)]); }
    public function verify(Request $r) {
        $v = $r->validate(['otp' => 'required|string|max:32']);
        $codes = $this->mfa->verify($r, $v['otp']);
        return $this->result($r, $codes ? ['authenticated' => true, 'recovery_codes' => $codes] : ['authenticated' => true]);
    }
    public function recover(Request $r) {
        $v = $r->validate(['recovery_code' => 'required|string|max:128']);
        $this->mfa->recover($r, $v['recovery_code']); return $this->result($r, ['reenrollment_required' => true]);
    }
    public function session(Request $r) { return $this->result($r, ['authenticated' => true]); }
    public function reauthenticate(Request $r) {
        $v = $r->validate(['password' => 'required|string|max:128', 'otp' => 'required|string|max:32']);
        $this->mfa->reauthenticate($r, $v['password'], $v['otp']); return $this->result($r);
    }
    public function change(Request $r) { $this->mfa->changeFactor($r); return $this->result($r, ['reenrollment_required' => true]); }
    public function logout(Request $r) { $this->mfa->logout($r); return $this->result($r); }
}
