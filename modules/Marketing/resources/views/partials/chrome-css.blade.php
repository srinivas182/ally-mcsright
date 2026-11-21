{{-- SITE2: the header and footer of RightAlly's public pages (website, sign-up, Health Check), one look everywhere. --}}
<style>
        header.top{position:sticky;top:0;z-index:50;background:rgb(255 255 255/.86);backdrop-filter:saturate(1.4) blur(12px);border-bottom:1px solid rgb(6 22 63/.06)}
        header.top .wrap{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;min-height:74px;gap:8px 20px}
        .brand{display:inline-flex;color:#09090B;text-decoration:none}.brand svg{height:34px;width:auto}
        nav.main{display:flex;flex-wrap:wrap;gap:2px 6px;align-items:center}
        nav.main a{text-decoration:none;color:var(--ink);padding:10px 12px;border-radius:10px;font-weight:600;font-size:16px}
        nav.main a:hover{background:var(--tint)}
        nav.main .login{color:var(--brand-deep)}
        nav.main a.btn{color:#fff;white-space:nowrap;padding:0 18px}nav.main a.btn:hover{background:#0F4FDB}
        nav.main .links{display:contents}
        details.menu{display:none;position:relative}
        details.menu summary{list-style:none;cursor:pointer;display:grid;place-items:center;width:46px;height:46px;border:1px solid var(--line);border-radius:12px;color:var(--ink)}
        details.menu summary svg{width:22px;height:22px}details.menu summary .open{display:none}details.menu[open] summary .open{display:block}details.menu[open] summary .closed{display:none}
        details.menu summary::-webkit-details-marker{display:none}
        details.menu>div{position:absolute;right:0;top:calc(100% + 8px);background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:8px;display:grid;min-width:220px}
        @media (max-width:899px){nav.main .links{display:none}details.menu{display:block}header.top .wrap{flex-wrap:nowrap}nav.main{flex-wrap:nowrap}nav.main .login{display:none}.brand svg{height:28px}}
        details.lang{position:relative}details.lang summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:6px;padding:10px 12px;border-radius:10px;font-weight:600;font-size:16px;color:var(--ink)}
        details.lang summary::-webkit-details-marker{display:none}details.lang summary:hover{background:var(--tint)}details.lang summary svg{width:18px;height:18px}
        details.lang>div{position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:6px;display:grid;min-width:170px;z-index:60}
        details.lang a{display:flex;justify-content:space-between;gap:12px}details.lang a[aria-current]::after{content:"✓";color:var(--brand)}
        details.menu .lang-row{border-top:1px solid var(--line);margin-top:6px;padding-top:6px;display:grid}
        @media (max-width:899px){details.lang{display:none}}
        footer{background:var(--navy);color:#AFC0E6;padding:56px 0 40px;font-size:16px}
        footer .cols{display:grid;gap:28px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
        footer a{color:#DCE5FB;text-decoration:none}footer a:hover{text-decoration:underline}
        footer h3{color:#fff;font-size:16px;margin:0 0 10px}footer ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}
        footer .brand{color:#fff}footer .legal{border-top:1px solid rgb(255 255 255/.1);margin-top:36px;padding-top:20px;font-size:14px;color:#8EA0CC;text-align:center;display:flex;flex-wrap:wrap;justify-content:center;gap:6px 18px}footer .legal p{margin:0}
        header.top .btn{display:inline-flex;align-items:center;justify-content:center;min-height:54px;padding:0 26px;border-radius:14px;border:1px solid transparent;background:var(--brand);color:#fff;font-family:inherit;font-weight:650;font-size:17px;line-height:1;text-decoration:none;margin:6px 10px 0 0;box-shadow:0 1px 0 rgb(255 255 255/.18) inset,0 10px 24px -10px rgb(21 93 252/.75);transition:background .15s}
        header.top .btn:hover{background:#0F4FDB}
        header.top .btn.small{min-height:44px;padding:0 18px;font-size:16px;margin:0}
        header.top .wrap,footer.site .wrap{max-width:1180px;margin:0 auto;padding:0 20px}
</style>
