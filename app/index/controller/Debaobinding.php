<?php

namespace app\index\controller;

use app\integration\debao\DebaoBindingService;

final class Debaobinding extends Common
{
    public function Index()
    {
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');

        $challengeId = trim((string)($this->data_request['challenge_id'] ?? ''));
        if(preg_match('/^dbc_[a-f0-9]{32}$/', $challengeId) !== 1) $challengeId = '';
        $isLoggedIn = !empty($this->user['id']);
        $result = null;
        $error = '';
        $csrfKey = 'debao_binding_csrf_'.hash('sha256', $challengeId);
        $csrfToken = (string)MySession($csrfKey);
        if($csrfToken === '')
        {
            $csrfToken = bin2hex(random_bytes(32));
            MySession($csrfKey, $csrfToken);
        }

        if(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST')
        {
            $submittedCsrf = trim((string)($this->data_post['csrf_token'] ?? ''));
            if(!$isLoggedIn)
            {
                $error = '请先登录当前 DeShop 商城账号。';
            } elseif($submittedCsrf === '' || !hash_equals($csrfToken, $submittedCsrf)) {
                $error = '确认页面已失效，请刷新后重试。';
            } else {
                MySession($csrfKey, null);
                $approval = DebaoBindingService::approveChallenge(
                    $challengeId,
                    trim((string)($this->data_post['challenge_token'] ?? '')),
                    (int)$this->user['id'],
                    time()
                );
                if((int)($approval['code'] ?? -1) === 0)
                {
                    $result = $approval['data'];
                } else {
                    $messages = [
                        'INVALID_CONFIRMATION_TOKEN'=>'绑定入口无效，请从得宝重新创建。',
                        'CHALLENGE_EXPIRED'=>'绑定入口已过期，请从得宝重新创建。',
                        'CHALLENGE_ALREADY_USED'=>'该绑定入口已经使用，不能重复确认。',
                        'BINDING_CONFLICT'=>'当前商城账号或得宝账号已绑定其他账号。',
                    ];
                    $error = $messages[$approval['error'] ?? ''] ?? '绑定确认失败，请重新创建绑定入口。';
                }
            }
        }

        MyViewAssign([
            'challenge_id'=>$challengeId,
            'is_logged_in'=>$isLoggedIn,
            'csrf_token'=>$csrfToken,
            'binding_result'=>$result,
            'binding_error'=>$error,
            'login_url'=>MyUrl('index/user/logininfo'),
            'home_seo_site_title'=>'确认关联得宝账号',
        ]);
        return MyView();
    }
}
