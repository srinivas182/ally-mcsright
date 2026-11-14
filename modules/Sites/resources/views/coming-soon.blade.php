<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{{ $site->displayName() }} | Coming soon</title><link rel="stylesheet" href="/site-assets/site.css?v=2"></head>
<body class="center-page"><main><h1>{{ $site->displayName() }}</h1><p>Our new website is coming soon.</p>
@if (! empty($site->info['phone']))<p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">{{ $site->info['phone'] }}</a></p>@endif</main></body></html>
