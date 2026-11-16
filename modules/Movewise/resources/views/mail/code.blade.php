<!doctype html><html lang="{{ $lang }}"><body style="font-family:system-ui,sans-serif;color:#14211f;max-width:520px;margin:0 auto;padding:24px">
<p style="font-size:20px;font-weight:700">Movewise Homes</p>
<p>{{ $lang === 'es' ? 'Tu código para iniciar sesión es:' : 'Your sign-in code is:' }}</p>
<p style="font-size:32px;font-weight:800;letter-spacing:6px">{{ $code }}</p>
<p>{{ $lang === 'es' ? 'Vence en 10 minutos. Si no lo pediste, ignora este correo.' : "It expires in 10 minutes. If you didn't ask for it, ignore this email." }}</p>
</body></html>
