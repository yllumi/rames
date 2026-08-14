<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="/favicon.ico"/>
    <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
    <title>webman</title>
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
  <div class="card shadow-sm mx-auto" style="max-width:420px;">
    <div class="card-body p-5 text-center">
      <h1 class="h3 mb-3">Hello <?= htmlspecialchars($name) ?></h1>
      <p class="text-muted mb-0">webman</p>
    </div>
  </div>
</div>
</body>
</html>
