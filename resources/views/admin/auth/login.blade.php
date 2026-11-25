<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GEOSEO 智能内容中台</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        .bg-glow {
            position: absolute;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.3;
            animation: float 8s ease-in-out infinite;
        }
        .glow-1 { width: 600px; height: 600px; background: #10b981; top: -200px; left: -200px; }
        .glow-2 { width: 500px; height: 500px; background: #0891b2; bottom: -150px; right: -150px; animation-delay: -4s; }
        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(30px, 30px); }
        }
        .bg-grid {
            position: absolute;
            inset: 0;
            background-image: 
                linear-gradient(rgba(16, 185, 129, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(16, 185, 129, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .login-card {
            position: relative;
            width: 100%;
            max-width: 440px;
            background: rgba(30, 41, 59, 0.8);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
        }
        .logo-section {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
        }
        .logo-title {
            font-size: 24px;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 8px;
        }
        .logo-subtitle {
            font-size: 14px;
            color: #94a3b8;
        }
        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: #475569;
            font-size: 12px;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #334155;
        }
        .divider span {
            padding: 0 12px;
        }
        .btn-wecom {
            width: 100%;
            padding: 14px;
            background: #0066ff;
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }
        .btn-wecom:hover {
            background: #0052cc;
            transform: translateY(-1px);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(51, 65, 85, 0.8);
            border-radius: 12px;
            color: #f1f5f9;
            font-size: 15px;
            transition: all 0.3s;
            outline: none;
        }
        .form-input:focus {
            border-color: #10b981;
        }
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #10b981 0%, #0891b2 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 20px;
            font-size: 11px;
            color: #10b981;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="bg-grid"></div>
    <div class="bg-glow glow-1"></div>
    <div class="bg-glow glow-2"></div>

    <div class="login-card">
        <div class="logo-section">
            <div class="badge">GEOSEO 智能内容中台 v3.0</div>
            <div class="logo-icon"></div>
            <h1 class="logo-title">GEOSEO 智能中台</h1>
            <p class="logo-subtitle">AI内容生产 · 审核 · 推广 全流程自动化</p>
        </div>

        @if (session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <a href="https://geo.aixgn.cn/sso/start.php?r=/geoflow/geo_admin" class="btn-wecom">
            企业微信扫码登录
        </a>

        <div class="divider">
            <span>账号密码登录</span>
        </div>

        <form method="POST" action="{{ route('admin.login.attempt') }}">
            @csrf
            <div class="form-group">
                <label class="form-label">用户名</label>
                <input type="text" name="username" required value="{{ old('username') }}"
                       class="form-input" placeholder="请输入用户名">
            </div>
            <div class="form-group">
                <label class="form-label">密码</label>
                <input type="password" name="password" required
                       class="form-input" placeholder="请输入密码">
            </div>
            <button type="submit" class="btn-login">
                进入中台控制台
            </button>
        </form>
    </div>
</body>
</html>
