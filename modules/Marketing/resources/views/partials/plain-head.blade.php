{{-- BR1: shared look for RightAlly's small standalone pages (log in finder, page not found). --}}
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><meta name="theme-color" content="#06369E">
<link rel="icon" href="/favicon.ico?v=br1" sizes="any"><link rel="icon" href="/favicon.svg?v=br1" type="image/svg+xml">
<style>
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;display:grid;background:#F4F6FB;color:#0A1330;font:400 18px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
    @media (min-width:960px){body{grid-template-columns:5fr 6fr}}
    .panel{display:none;position:relative;overflow:hidden;background:#04163F;color:#fff;padding:56px}
    @media (min-width:960px){.panel{display:flex;flex-direction:column;justify-content:space-between}}
    .panel::before{content:"";position:absolute;inset:0;opacity:.14;background-image:linear-gradient(to right,#8FB3FF 1px,transparent 1px),linear-gradient(to bottom,#8FB3FF 1px,transparent 1px);background-size:44px 44px;-webkit-mask-image:radial-gradient(ellipse at 30% 40%,#000 20%,transparent 75%);mask-image:radial-gradient(ellipse at 30% 40%,#000 20%,transparent 75%)}
    .panel::after{content:"";position:absolute;right:-160px;bottom:-190px;width:576px;height:576px;border-radius:50%;background:#155DFC;opacity:.4;filter:blur(120px)}
    .panel>*{position:relative;z-index:1}
    .panel .say{font-size:38px;line-height:1.12;font-weight:600;letter-spacing:-.02em;max-width:26rem;margin:0}
    .panel .safe{color:#9FB2DE;font-size:15px;margin:0}
    .logo{height:32px;width:auto;display:block}
    main{display:flex;align-items:center;justify-content:center;padding:40px 20px}
    .box{width:100%;max-width:27rem}
    .top{display:block;margin-bottom:28px;color:#09090B}.top .logo{height:30px}
    @media (min-width:960px){.top{display:none}}
    .card{background:#fff;border:1px solid #E3E8F2;border-radius:18px;padding:36px;box-shadow:0 1px 2px rgb(6 22 63/.04),0 12px 32px -12px rgb(6 22 63/.18)}
    h1{font-size:27px;line-height:1.2;letter-spacing:-.015em;margin:0 0 8px}
    p.lead{color:#52607A;margin:0 0 24px;font-size:17px}
    label{display:block;font-weight:600;font-size:16px;margin-bottom:8px}
    .field{display:flex;align-items:stretch;border:1px solid #C9D2E3;border-radius:11px;background:#fff;overflow:hidden}
    .field:focus-within{outline:3px solid #155DFC;outline-offset:1px;border-color:#155DFC}
    .field input{flex:1;min-width:0;border:0;padding:0 14px;height:50px;font:inherit;color:inherit;background:transparent;outline:0}
    .field span{display:flex;align-items:center;padding:0 14px;background:#F1F4FA;color:#52607A;border-left:1px solid #E3E8F2;font-size:16px}
    .err{color:#B42318;font-size:16px;margin:10px 0 0}
    .btn{display:flex;width:100%;align-items:center;justify-content:center;height:52px;margin-top:20px;border:0;border-radius:11px;background:#155DFC;color:#fff;font-family:inherit;font-size:18px;font-weight:600;line-height:1;cursor:pointer;text-decoration:none;box-shadow:0 1px 0 rgb(255 255 255/.15) inset,0 8px 20px -8px rgb(21 93 252/.6)}
    .btn:hover{background:#0F4FDB}
    .btn:focus-visible,a:focus-visible{outline:3px solid #155DFC;outline-offset:2px}
    .help{margin:22px 0 0;padding-top:20px;border-top:1px solid #E9EDF5;color:#52607A;font-size:16px}
    a{color:#0B3FB8}
    .foot{margin:22px 0 0;text-align:center;color:#52607A;font-size:15px}
</style>
