<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Certificate</title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; text-align: center; }
    .frame { border: 6px double #1f3a5f; padding: 48px 40px; margin: 10px; }
    h1 { font-size: 34px; margin: 0 0 8px; color: #1f3a5f; }
    .name { font-size: 30px; font-weight: bold; margin: 24px 0 8px; }
    .course { font-size: 22px; margin: 8px 0 24px; }
    .muted { color: #555; font-size: 13px; }
</style>
</head>
<body>
<div class="frame">
    <h1>Certificate of completion</h1>
    <div class="muted">{{ $brokerage }}</div>
    <div class="name">{{ $name }}</div>
    <div>completed the course</div>
    <div class="course">{{ $course }}</div>
    <div>on {{ $date }} · about {{ $minutes }} minutes</div>
    <p class="muted" style="margin-top:36px">Certificate {{ $id }}. Brokerage training; not a state continuing-education credit unless your state approves it.</p>
</div>
</body>
</html>
