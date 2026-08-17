<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hello World · Rames</title>
<link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/css/app.css">
<style>
  .hello-wrap { position: relative; z-index: 1; text-align: center; max-width: 560px; padding: 20px; }
  .hello-logo { display: inline-block; margin-bottom: 18px; }
  .hello-logo img {
    width: 150px; height: 150px; object-fit: contain;
    filter: drop-shadow(0 18px 30px rgba(13, 148, 136, .35));
    animation: hello-float 6s ease-in-out infinite;
  }
  @keyframes hello-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
  }
  .hello-title {
    font-size: clamp(2.2rem, 6vw, 3.4rem); font-weight: 800; letter-spacing: .01em;
    background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .hello-sub { color: var(--bs-secondary-color, #5b7a84); font-size: 16px; margin: 12px auto 30px; max-width: 440px; }
</style>
</head>
<body class="auth-body">
<div class="auth-glow"></div>
<div class="hello-wrap">
  <div class="hello-logo">
    <img src="/logo-rames.png" alt="rames">
  </div>
  <h1 class="hello-title">Hello, World!</h1>
</div>
<script src="/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
