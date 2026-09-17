<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BMW M3 Competition 2023 — Sfiso's Auto Desk | SalesDesk</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    /* ============================================================
       DESIGN TOKENS — shared with global.css
       ============================================================ */
    :root {
      --p:          #0f4c9e;
      --p-dark:     #0c3273;
      --p-mid:      #1a5abf;
      --p-light:    #eff4ff;
      --p-border:   #dbeafe;
      --dark:       #08143c;
      --text:       #1e293b;
      --muted:      #64748b;
      --faint:      #94a3b8;
      --border:     #e2e8f0;
      --border-l:   #f1f5f9;
      --bg:         #f3f4f8;
      --white:      #ffffff;
      --green:      #15803d;
      --green-bg:   #f0fdf4;
      --green-b:    #bbf7d0;
      --green-mid:  #22c55e;
      --amber:      #b45309;
      --amber-bg:   #fffbeb;
      --amber-b:    #fde68a;
      --red:        #dc2626;
      --red-bg:     #fef2f2;
      --red-b:      #fecaca;
      --font-d:     'Sora', sans-serif;
      --font-b:     'DM Sans', sans-serif;
      --r-xs:  4px;  --r-sm:  8px;  --r-md:  12px;
      --r-lg:  16px; --r-xl:  20px; --r-pill:40px;
      --sh-card:  0 2px 10px rgba(0,0,0,.05);
      --sh-hover: 0 16px 32px rgba(15,76,158,.13);
      --sh-md:    0 4px 16px rgba(0,0,0,.08);
      --sh-lg:    0 8px 32px rgba(0,0,0,.12);
      --tr:   0.2s ease;
      --tr-s: 0.35s cubic-bezier(.2,0,0,1);
      --max:  1200px;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; scroll-behavior: smooth; }
    body { font-family: var(--font-b); background: var(--bg); color: var(--text);
           -webkit-font-smoothing: antialiased; line-height: 1.5; }
    a { text-decoration: none; color: inherit; }
    img { display: block; max-width: 100%; }
    ul { list-style: none; }
    button { cursor: pointer; font-family: inherit; border: none; background: none; }

    /* ============================================================
       NAV
       ============================================================ */
    .nav { position:sticky;top:0;z-index:100;height:60px;background:rgba(255,255,255,.96);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .nav__inner { max-width:1280px;margin:0 auto;padding:0 24px;height:100%;display:flex;align-items:center;justify-content:space-between;gap:24px; }
    .nav__brand { display:flex;align-items:center;gap:10px;flex-shrink:0;text-decoration:none; }
    .nav__logo { width:32px;height:32px;background:var(--p);border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px; }
    .nav__name { font-family:var(--font-d);font-size:20px;font-weight:700;color:var(--text); }
    .nav__name span { color:var(--p); }
    .nav__badge { font-size:10px;font-weight:700;letter-spacing:.06em;background:var(--green-bg);color:var(--green);border:1px solid var(--green-b);border-radius:var(--r-pill);padding:2px 8px; }
    .nav__links { display:flex;align-items:center;gap:28px;flex:1;justify-content:center; }
    .nav__link { font-size:14px;font-weight:500;color:var(--muted);transition:color var(--tr);text-decoration:none; }
    .nav__link:hover { color:var(--p); }
    .nav__actions { display:flex;align-items:center;gap:14px;flex-shrink:0; }
    .nav__signin { font-size:14px;font-weight:500;color:var(--text);transition:color var(--tr);text-decoration:none; }
    .nav__signin:hover { color:var(--p); }

    /* My Account dropdown */
    .nav__account { position:relative; }
    .nav__account-btn { display:inline-flex;align-items:center;gap:8px;background:var(--p);color:#fff;border-radius:var(--r-md);padding:10px 18px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;border:none;transition:background var(--tr); }
    .nav__account-btn:hover { background:var(--p-dark); }
    .nav__account-btn .chevron { font-size:10px;transition:transform .2s ease;display:inline-block; }
    .nav__account-btn.open .chevron { transform:rotate(180deg); }
    .nav__dropdown { position:absolute;top:calc(100% + 10px);right:0;background:#fff;border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:0 12px 32px rgba(0,0,0,.12),0 2px 8px rgba(0,0,0,.06);min-width:200px;padding:8px;z-index:200;opacity:0;visibility:hidden;transform:translateY(-6px);transition:opacity .2s ease,transform .2s ease,visibility .2s; }
    .nav__dropdown.open { opacity:1;visibility:visible;transform:translateY(0); }
    .nav__dropdown-item { display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r-md);font-size:13px;font-weight:500;color:var(--text);text-decoration:none;transition:background var(--tr),color var(--tr); }
    .nav__dropdown-item i { font-size:13px;color:var(--faint);width:16px;text-align:center; }
    .nav__dropdown-item:hover { background:var(--p-light);color:var(--p); }
    .nav__dropdown-item:hover i { color:var(--p); }
    .nav__dropdown-divider { height:1px;background:var(--border-l);margin:6px 0; }
    .nav__dropdown-item.danger { color:#dc2626; }
    .nav__dropdown-item.danger i { color:#dc2626; }
    .nav__dropdown-item.danger:hover { background:#fef2f2; }

    /* Browse dropdown */
    .nav__browse { position:relative; }
    .nav__browse-btn { display:inline-flex;align-items:center;gap:5px;font-size:14px;font-weight:500;color:var(--muted);background:none;border:none;cursor:pointer;font-family:inherit;padding:0;transition:color var(--tr); }
    .nav__browse-btn:hover,.nav__browse-btn.open { color:var(--p); }
    .nav__browse-btn .chevron { font-size:9px;transition:transform .2s ease;display:inline-block; }
    .nav__browse-btn.open .chevron { transform:rotate(180deg); }
    .nav__browse-panel { position:absolute;top:calc(100% + 14px);left:50%;transform:translateX(-50%) translateY(-6px);background:#fff;border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:0 16px 40px rgba(0,0,0,.12),0 2px 8px rgba(0,0,0,.06);min-width:240px;padding:8px;z-index:200;opacity:0;visibility:hidden;transition:opacity .2s ease,transform .2s ease,visibility .2s; }
    .nav__browse-panel.open { opacity:1;visibility:visible;transform:translateX(-50%) translateY(0); }
    .nav__browse-panel-title { font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--faint);padding:6px 12px 4px; }
    .nav__browse-item { display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r-md);font-size:13px;font-weight:500;color:var(--text);text-decoration:none;transition:background var(--tr),color var(--tr); }
    .nav__browse-item i { font-size:12px;color:var(--faint);width:16px;text-align:center; }
    .nav__browse-item:hover { background:var(--p-light);color:var(--p); }
    .nav__browse-item:hover i { color:var(--p); }
    .nav__browse-divider { height:1px;background:var(--border-l);margin:6px 0; }

    /* Breadcrumb */
    .nav__breadcrumb { display:flex;align-items:center;gap:8px;font-size:13px;color:var(--faint);flex:1; }
    .nav__breadcrumb a { color:var(--muted);transition:color var(--tr);text-decoration:none; }
    .nav__breadcrumb a:hover { color:var(--p); }
    .nav__breadcrumb .sep { color:var(--border); }
    .nav__breadcrumb .current { color:var(--text);font-weight:500; }

    /* Utility buttons */
    .btn { display:inline-flex;align-items:center;justify-content:center;gap:8px;font-family:inherit;font-weight:600;border:none;cursor:pointer;transition:all var(--tr);white-space:nowrap; }
    .btn-primary { background:var(--p);color:#fff;border-radius:var(--r-md);padding:9px 20px;font-size:13px; }
    .btn-primary:hover { background:var(--p-dark); }
    .btn-ghost { background:var(--white);color:var(--text);border:1.5px solid var(--border);border-radius:var(--r-md);padding:8px 18px;font-size:13px;text-decoration:none; }
    .btn-ghost:hover { border-color:var(--p);color:var(--p); }
    .btn-icon { width:36px;height:36px;border-radius:var(--r-sm);background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--muted);transition:all var(--tr); }
    .btn-icon:hover { background:var(--p-light);color:var(--p);border-color:var(--p-border); }
    .btn-icon.active { background:var(--red-bg);color:var(--red);border-color:var(--red-b); }

    @media(max-width:1024px) { .nav__links { display:none; } }
    @media(max-width:768px)  { .nav__signin { display:none; } }
    @media(max-width:480px)  { .nav__account-btn span.label { display:none; } .nav__account-btn { padding:10px 14px; } }

    /* ============================================================
       LAYOUT
       ============================================================ */
    .page { max-width: var(--max); margin: 0 auto; padding: 28px 24px; }
    .detail-grid { display: grid; grid-template-columns: 1fr 360px; gap: 28px; align-items: start; }

    /* ============================================================
       GALLERY
       ============================================================ */
    .gallery { margin-bottom: 24px; }
    .gallery__main {
      position: relative; height: 440px; overflow: hidden;
      border-radius: var(--r-xl); background: var(--dark);
      cursor: zoom-in; box-shadow: var(--sh-lg);
    }
    .gallery__main img {
      width: 100%; height: 100%; object-fit: cover;
      transition: transform var(--tr-s);
    }
    .gallery__main:hover img { transform: scale(1.03); }
    .gallery__badge-row {
      position: absolute; top: 16px; left: 16px;
      display: flex; gap: 8px; align-items: center;
    }
    .gallery__year {
      background: rgba(0,0,0,.6); backdrop-filter: blur(8px);
      color: #fff; font-family: var(--font-d); font-size: 12px; font-weight: 700;
      padding: 4px 12px; border-radius: var(--r-pill);
    }
    .gallery__prov {
      background: rgba(255,255,255,.92); font-size: 12px; font-weight: 600;
      color: var(--text); padding: 4px 12px; border-radius: var(--r-pill);
    }
    .gallery__km {
      position: absolute; bottom: 16px; right: 16px;
      background: rgba(255,255,255,.92); font-size: 12px; font-weight: 600;
      color: var(--text); padding: 4px 12px; border-radius: var(--r-pill);
      box-shadow: 0 2px 8px rgba(0,0,0,.1);
    }
    .gallery__photo-count {
      position: absolute; bottom: 16px; left: 16px;
      background: rgba(0,0,0,.5); backdrop-filter: blur(6px);
      color: rgba(255,255,255,.85); font-size: 12px; font-weight: 500;
      padding: 4px 12px; border-radius: var(--r-pill);
    }
    .gallery__thumbs { display: flex; gap: 10px; margin-top: 10px; overflow-x: auto; padding-bottom: 4px; }
    .gallery__thumbs::-webkit-scrollbar { height: 3px; }
    .gallery__thumbs::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
    .gallery__thumb {
      width: 88px; height: 60px; flex-shrink: 0;
      border-radius: var(--r-md); overflow: hidden; cursor: pointer;
      border: 2.5px solid transparent; transition: all var(--tr);
    }
    .gallery__thumb img { width: 100%; height: 100%; object-fit: cover; }
    .gallery__thumb.active { border-color: var(--p); box-shadow: 0 0 0 2px rgba(15,76,158,.2); }
    .gallery__thumb:hover:not(.active) { border-color: var(--border); }

    /* ============================================================
       CAR HEADER
       ============================================================ */
    .car-header { margin-bottom: 24px; }
    .car-header__top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
    .car-header__name {
      font-family: var(--font-d); font-size: 26px; font-weight: 800;
      color: var(--text); line-height: 1.15; margin-bottom: 6px;
    }
    .car-header__dealer { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 6px; }
    .car-header__dealer i { color: var(--faint); }
    .car-header__price {
      font-family: var(--font-d); font-size: 30px; font-weight: 800;
      color: var(--p); text-align: right; flex-shrink: 0;
    }
    .car-header__pm { font-size: 12px; color: var(--faint); text-align: right; margin-top: 2px; }
    .badge-verified {
      display: inline-flex; align-items: center; gap: 4px;
      background: var(--green-bg); color: var(--green); border: 1px solid var(--green-b);
      font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: var(--r-pill);
    }
    .badge-verified i { font-size: 9px; }
    .badge-desk {
      display: inline-flex; align-items: center; gap: 4px;
      background: var(--p-light); color: var(--p); border: 1px solid var(--p-border);
      font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: var(--r-pill);
      font-family: var(--font-d);
    }
    .badge-desk i { font-size: 9px; }
    .car-header__badges { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }

    /* ============================================================
       SPEC CHIPS
       ============================================================ */
    .spec-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px; }
    .spec-chip {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--white); border: 1px solid var(--border);
      color: var(--text); font-size: 12px; font-weight: 500;
      padding: 7px 14px; border-radius: var(--r-pill);
      box-shadow: var(--sh-card);
    }
    .spec-chip i { color: var(--p); font-size: 11px; }
    .spec-chip strong { font-weight: 700; }

    /* ============================================================
       SPEC TABLE
       ============================================================ */
    .spec-section { margin-bottom: 28px; }
    .spec-section__title {
      font-family: var(--font-d); font-size: 15px; font-weight: 700;
      margin-bottom: 14px; color: var(--text);
      display: flex; align-items: center; gap: 8px;
    }
    .spec-section__title::after {
      content: ''; flex: 1; height: 1px; background: var(--border-l);
    }
    .spec-table { background: var(--white); border: 1px solid var(--border-l); border-radius: var(--r-lg); overflow: hidden; box-shadow: var(--sh-card); }
    .spec-table__row {
      display: flex; align-items: center;
      padding: 12px 18px; border-bottom: 1px solid var(--border-l);
    }
    .spec-table__row:last-child { border-bottom: none; }
    .spec-table__row:nth-child(even) { background: #fafcff; }
    .spec-table__key { font-size: 12px; color: var(--faint); width: 140px; flex-shrink: 0; }
    .spec-table__val { font-size: 13px; font-weight: 600; color: var(--text); flex: 1; }
    .spec-table__val.highlight { color: var(--p); font-family: var(--font-d); }

    /* ============================================================
       DESCRIPTION
       ============================================================ */
    .desc-block {
      background: var(--white); border: 1px solid var(--border-l);
      border-radius: var(--r-lg); padding: 20px; margin-bottom: 24px;
      box-shadow: var(--sh-card);
    }
    .desc-block__title { font-family: var(--font-d); font-size: 14px; font-weight: 700; margin-bottom: 12px; }
    .desc-block__text { font-size: 13px; color: var(--muted); line-height: 1.75; }
    .desc-block__text.clamped { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .desc-block__more { font-size: 12px; color: var(--p); font-weight: 600; margin-top: 10px; cursor: pointer; }

    /* ============================================================
       DEALER CARD
       ============================================================ */
    .dealer-card {
      background: var(--white); border: 1px solid var(--border-l);
      border-radius: var(--r-lg); padding: 18px; margin-bottom: 24px;
      box-shadow: var(--sh-card);
    }
    .dealer-card__header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
    .dealer-card__icon {
      width: 42px; height: 42px; border-radius: var(--r-md);
      background: var(--p-light); display: flex; align-items: center; justify-content: center;
      color: var(--p); font-size: 16px; flex-shrink: 0;
    }
    .dealer-card__name { font-family: var(--font-d); font-size: 14px; font-weight: 700; }
    .dealer-card__loc { font-size: 12px; color: var(--faint); margin-top: 2px; }
    .dealer-card__stats { display: flex; gap: 16px; padding: 12px 0; border-top: 1px solid var(--border-l); border-bottom: 1px solid var(--border-l); margin-bottom: 12px; }
    .dealer-stat { text-align: center; flex: 1; }
    .dealer-stat__num { font-family: var(--font-d); font-size: 18px; font-weight: 700; color: var(--text); }
    .dealer-stat__lbl { font-size: 10px; color: var(--faint); margin-top: 2px; }

    /* ============================================================
       STICKY ENQUIRY SIDEBAR
       ============================================================ */
    .enquiry-sticky { position: sticky; top: 80px; }
    .enquiry-card {
      background: var(--white); border: 1px solid var(--border-l);
      border-radius: var(--r-xl); box-shadow: var(--sh-lg);
      overflow: hidden;
    }
    .enquiry-card__head {
      background: linear-gradient(140deg, var(--dark) 0%, var(--p) 100%);
      padding: 22px; position: relative; overflow: hidden;
    }
    .enquiry-card__head::before {
      content: ''; position: absolute; top: -30px; right: -30px;
      width: 120px; height: 120px; border-radius: 50%;
      background: rgba(255,255,255,.07);
    }
    .enquiry-card__price {
      font-family: var(--font-d); font-size: 28px; font-weight: 800;
      color: #fff; line-height: 1; margin-bottom: 4px;
    }
    .enquiry-card__pm { font-size: 12px; color: rgba(255,255,255,.5); }
    .enquiry-card__commission {
      display: flex; align-items: center; gap: 6px;
      background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18);
      border-radius: var(--r-pill); padding: 6px 12px; margin-top: 14px;
      width: fit-content;
    }
    .enquiry-card__commission-label { font-size: 11px; color: rgba(255,255,255,.65); }
    .enquiry-card__commission-val { font-family: var(--font-d); font-size: 12px; font-weight: 700; color: #4ade80; }
    .enquiry-card__body { padding: 20px; }
    .enquiry-broker {
      display: flex; align-items: center; gap: 10px;
      background: var(--p-light); border: 1px solid var(--p-border);
      border-radius: var(--r-md); padding: 10px 14px; margin-bottom: 16px;
    }
    .enquiry-broker__av {
      width: 32px; height: 32px; border-radius: 50%;
      background: linear-gradient(135deg,#3b82f6,#1d4ed8);
      display: flex; align-items: center; justify-content: center;
      font-family: var(--font-d); font-size: 12px; font-weight: 700; color: #fff;
      flex-shrink: 0;
    }
    .enquiry-broker__name { font-size: 12px; font-weight: 600; color: var(--p); }
    .enquiry-broker__sub { font-size: 11px; color: var(--muted); }
    .form-label { display: block; font-size: 11px; font-weight: 700; letter-spacing: .04em; color: var(--text); margin-bottom: 5px; }
    .form-input {
      width: 100%; padding: 10px 14px;
      border: 1.5px solid var(--border); border-radius: var(--r-md);
      font-size: 13px; font-family: var(--font-b); color: var(--text);
      background: #f8faff; outline: none; transition: border-color var(--tr), background var(--tr);
      margin-bottom: 12px;
    }
    .form-input:focus { border-color: var(--p); background: var(--white); }
    .form-input::placeholder { color: var(--faint); }
    textarea.form-input { resize: none; margin-bottom: 0; }
    select.form-input { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='7' viewBox='0 0 10 7'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px; }
    .form-submit {
      width: 100%; padding: 13px; background: var(--p); color: #fff;
      font-size: 14px; font-weight: 700; border-radius: var(--r-md);
      transition: background var(--tr); display: flex; align-items: center;
      justify-content: center; gap: 8px; margin-top: 14px;
    }
    .form-submit:hover { background: var(--p-dark); }
    .form-submit i { font-size: 12px; }
    .form-or { text-align: center; font-size: 12px; color: var(--faint); margin: 12px 0; position: relative; }
    .form-or::before, .form-or::after {
      content: ''; position: absolute; top: 50%;
      width: calc(50% - 24px); height: 1px; background: var(--border-l);
    }
    .form-or::before { left: 0; }
    .form-or::after  { right: 0; }
    .form-whatsapp {
      width: 100%; padding: 11px; background: #25d366; color: #fff;
      font-size: 13px; font-weight: 600; border-radius: var(--r-md);
      transition: background var(--tr); display: flex; align-items: center;
      justify-content: center; gap: 8px;
    }
    .form-whatsapp:hover { background: #1ebe5d; }
    .form-attribution {
      font-size: 11px; color: var(--faint); text-align: center;
      margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border-l);
      display: flex; flex-direction: column; gap: 4px;
    }
    .form-attribution span { display: flex; align-items: center; justify-content: center; gap: 5px; }
    .form-attribution i { color: var(--p); }

    /* ============================================================
       RELATED CARS
       ============================================================ */
    .related { padding: 40px 0 16px; }
    .related__title { font-family: var(--font-d); font-size: 20px; font-weight: 700; margin-bottom: 18px; }
    .related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
    .rel-card {
      background: var(--white); border: 1px solid var(--border-l);
      border-radius: var(--r-lg); overflow: hidden;
      transition: transform var(--tr-s), box-shadow var(--tr-s), border-color var(--tr);
      box-shadow: var(--sh-card); cursor: pointer;
    }
    .rel-card:hover { transform: translateY(-4px); box-shadow: var(--sh-hover); border-color: #c7d6f5; }
    .rel-card__img { height: 150px; overflow: hidden; background: var(--bg); }
    .rel-card__img img { width: 100%; height: 100%; object-fit: cover; transition: transform var(--tr-s); }
    .rel-card:hover .rel-card__img img { transform: scale(1.07); }
    .rel-card__body { padding: 12px; }
    .rel-card__name { font-family: var(--font-d); font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
    .rel-card__price { font-size: 14px; font-weight: 700; color: var(--p); font-family: var(--font-d); }
    .rel-card__meta { font-size: 11px; color: var(--faint); margin-top: 4px; }

    /* ============================================================
       ANIMATIONS
       ============================================================ */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .anim-up { animation: fadeUp .5s ease both; }
    .d1{animation-delay:.05s} .d2{animation-delay:.15s}
    .d3{animation-delay:.25s} .d4{animation-delay:.35s}

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 960px) {
      .detail-grid { grid-template-columns: 1fr; }
      .enquiry-sticky { position: static; }
      .gallery__main { height: 300px; }
      .nav__breadcrumb { display: none; }
    }
    @media (max-width: 600px) {
      .page { padding: 16px; }
      .car-header__name { font-size: 20px; }
      .car-header__price { font-size: 22px; }
      .gallery__main { height: 240px; }
    }
  </style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nav__inner">
    <a href="../index.php" class="nav__brand">
      <div class="nav__logo"><i class="fa-solid fa-car-side"></i></div>
      <span class="nav__name">Sales<span>Desk</span></span>
      <span class="nav__badge">ZA</span>
    </a>

    <nav class="nav__links">
      <div class="nav__browse" id="browseMenu">
        <button class="nav__browse-btn" id="browseBtn" aria-haspopup="true" aria-expanded="false">
          Browse Cars <span class="chevron">&#9660;</span>
        </button>
        <div class="nav__browse-panel" id="browsePanel" role="menu">
          <div class="nav__browse-panel-title">By Category</div>
          <a href="../c/index.php"                      class="nav__browse-item" role="menuitem"><i class="fa-solid fa-list"></i> All Cars</a>
          <a href="../c/index.php?bodyType=Bakkie"      class="nav__browse-item" role="menuitem"><i class="fa-solid fa-truck-monster"></i> Bakkies</a>
          <a href="../c/index.php?bodyType=suv"         class="nav__browse-item" role="menuitem"><i class="fa-solid fa-truck"></i> SUVs / 4x4</a>
          <a href="../c/index.php?bodyType=Hatchback"   class="nav__browse-item" role="menuitem"><i class="fa-solid fa-car"></i> Hatchbacks</a>
          <a href="../c/index.php?bodyType=Sedan"       class="nav__browse-item" role="menuitem"><i class="fa-solid fa-car-side"></i> Sedans</a>
          <a href="../c/index.php?fuelType=Electric"    class="nav__browse-item" role="menuitem"><i class="fa-solid fa-bolt"></i> Electric</a>
          <div class="nav__browse-divider"></div>
          <div class="nav__browse-panel-title">By Budget</div>
          <a href="../c/index.php?maxPrice=300000"      class="nav__browse-item" role="menuitem"><i class="fa-solid fa-tag"></i> Under R 300k</a>
          <a href="../c/index.php?minPrice=1000000"     class="nav__browse-item" role="menuitem"><i class="fa-solid fa-star"></i> Luxury</a>
          <div class="nav__browse-divider"></div>
          <div class="nav__browse-panel-title">By Condition</div>
          <a href="../c/index.php?condition=new"        class="nav__browse-item" role="menuitem"><i class="fa-solid fa-star-of-life"></i> New Cars</a>
          <a href="../c/index.php?condition=used"       class="nav__browse-item" role="menuitem"><i class="fa-solid fa-rotate-left"></i> Pre-Owned</a>
        </div>
      </div>
      <a href="../brokers.php" class="nav__link">For Brokers</a>
      <a href="../index.php#for-dealers" class="nav__link">For Dealers</a>
    </nav>

    <div class="nav__actions">
      <button class="btn-icon" id="wishlistBtn" title="Save to wishlist">
        <i class="fa-regular fa-heart"></i>
      </button>
      <button class="btn-icon" title="Share">
        <i class="fa-solid fa-share-nodes"></i>
      </button>
      <a href="../c/index.php" class="btn btn-ghost" style="font-size:13px;">
        <i class="fa-solid fa-arrow-left" style="font-size:11px;"></i> Back to browse
      </a>
      <a href="../signin.php" class="nav__signin">Sign in</a>
      <div class="nav__account" id="accountMenu">
        <button class="nav__account-btn" id="accountBtn" aria-haspopup="true" aria-expanded="false">
          <i class="fa-regular fa-circle-user"></i>
          <span class="label">My Account</span>
          <span class="chevron">&#9660;</span>
        </button>
        <div class="nav__dropdown" id="accountDropdown" role="menu">
          <a href="../profile.php"        class="nav__dropdown-item" role="menuitem"><i class="fa-regular fa-user"></i> Profile</a>
          <a href="../my-salesdesk.php"   class="nav__dropdown-item" role="menuitem"><i class="fa-solid fa-id-card"></i> My SalesDesk</a>
          <a href="../wishlist.php"       class="nav__dropdown-item" role="menuitem"><i class="fa-regular fa-heart"></i> Wishlist</a>
          <a href="../saved-searches.php" class="nav__dropdown-item" role="menuitem"><i class="fa-regular fa-bookmark"></i> Saved Searches</a>
          <a href="../earnings.php"       class="nav__dropdown-item" role="menuitem"><i class="fa-solid fa-chart-line"></i> Earnings</a>
          <div class="nav__dropdown-divider"></div>
          <a href="../settings.php"       class="nav__dropdown-item" role="menuitem"><i class="fa-solid fa-gear"></i> Settings</a>
          <a href="../signout.php"        class="nav__dropdown-item danger" role="menuitem"><i class="fa-solid fa-right-from-bracket"></i> Sign out</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Breadcrumb -->
  <div style="max-width:1280px;margin:0 auto;padding:0 24px 8px;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--faint);">
    <a href="../index.php" style="color:var(--muted);text-decoration:none;" onmouseover="this.style.color='var(--p)'" onmouseout="this.style.color='var(--muted)'">Home</a>
    <span style="color:var(--border);">/</span>
    <a href="../c/index.php" style="color:var(--muted);text-decoration:none;" onmouseover="this.style.color='var(--p)'" onmouseout="this.style.color='var(--muted)'">Browse</a>
    <span style="color:var(--border);">/</span>
    <span style="color:var(--text);font-weight:500;" id="breadcrumbCurrent">BMW M3 Competition 2023</span>
  </div>
</nav>

<div class="page">

  <div class="detail-grid">

    <!-- LEFT COLUMN -->
    <div>

      <!-- Gallery -->
      <div class="gallery anim-up">
        <div class="gallery__main" id="mainImg">
          <img src="https://images.unsplash.com/photo-1617531653332-bd46c24f2068?w=900&auto=format" alt="BMW M3 Competition" id="mainImgEl">
          <div class="gallery__badge-row">
            <span class="gallery__year">2023</span>
            <span class="gallery__prov"><i class="fa-solid fa-location-dot" style="color:var(--p);margin-right:4px;font-size:10px;"></i>Gauteng</span>
          </div>
          <span class="gallery__km"><i class="fa-solid fa-road" style="color:#94a3b8;margin-right:4px;"></i>18 400 km</span>
          <span class="gallery__photo-count"><i class="fa-regular fa-image" style="margin-right:5px;"></i>1 / 5</span>
        </div>
        <div class="gallery__thumbs">
          <div class="gallery__thumb active" onclick="setImg(this,'https://images.unsplash.com/photo-1617531653332-bd46c24f2068?w=900&auto=format','1')">
            <img src="https://images.unsplash.com/photo-1617531653332-bd46c24f2068?w=200&auto=format" alt="">
          </div>
          <div class="gallery__thumb" onclick="setImg(this,'https://images.unsplash.com/photo-1555215695-3004980ad54e?w=900&auto=format','2')">
            <img src="https://images.unsplash.com/photo-1555215695-3004980ad54e?w=200&auto=format" alt="">
          </div>
          <div class="gallery__thumb" onclick="setImg(this,'https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?w=900&auto=format','3')">
            <img src="https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?w=200&auto=format" alt="">
          </div>
          <div class="gallery__thumb" onclick="setImg(this,'https://images.unsplash.com/photo-1580273916550-e323be2ae537?w=900&auto=format','4')">
            <img src="https://images.unsplash.com/photo-1580273916550-e323be2ae537?w=200&auto=format" alt="">
          </div>
          <div class="gallery__thumb" onclick="setImg(this,'https://images.unsplash.com/photo-1617814076367-b759c7d7e738?w=900&auto=format','5')">
            <img src="https://images.unsplash.com/photo-1617814076367-b759c7d7e738?w=200&auto=format" alt="">
          </div>
        </div>
      </div>

      <!-- Car header -->
      <div class="car-header anim-up d2">
        <div class="car-header__top">
          <div>
            <div class="car-header__name">BMW M3 Competition</div>
            <div class="car-header__dealer">
              <i class="fa-regular fa-building"></i> BMW Midrand &middot; Gauteng
            </div>
          </div>
          <div>
            <div class="car-header__price">R 1 650 000</div>
            <div class="car-header__pm">~R 28 400 p/m est.</div>
          </div>
        </div>
        <div class="car-header__badges">
          <span class="badge-verified"><i class="fa-solid fa-circle-check"></i> Verified Dealer</span>
          <span class="badge-desk"><i class="fa-solid fa-id-card"></i> Elite Auto Desk</span>
          <span style="display:inline-flex;align-items:center;gap:4px;background:var(--green-bg);color:var(--green);border:1px solid var(--green-b);font-size:11px;font-weight:600;padding:3px 10px;border-radius:var(--r-pill);">
            <i class="fa-solid fa-circle" style="font-size:7px;"></i> Available
          </span>
        </div>
      </div>

      <!-- Spec chips -->
      <div class="spec-chips anim-up d3">
        <span class="spec-chip"><i class="fa-solid fa-calendar"></i> <strong>2023</strong></span>
        <span class="spec-chip"><i class="fa-solid fa-road"></i> <strong>18 400</strong> km</span>
        <span class="spec-chip"><i class="fa-solid fa-gas-pump"></i> <strong>Petrol</strong></span>
        <span class="spec-chip"><i class="fa-solid fa-gear"></i> <strong>Automatic</strong></span>
        <span class="spec-chip"><i class="fa-solid fa-car-side"></i> <strong>Sedan</strong></span>
        <span class="spec-chip"><i class="fa-solid fa-circle-dot"></i> <strong>RWD</strong></span>
        <span class="spec-chip"><i class="fa-solid fa-palette"></i> <strong>Isle of Man Blue</strong></span>
        <span class="spec-chip"><i class="fa-solid fa-tag"></i> Second-hand</span>
      </div>

      <!-- Description -->
      <div class="desc-block anim-up d3">
        <div class="desc-block__title">About this car</div>
        <div class="desc-block__text clamped" id="descText">
          This 2023 BMW M3 Competition is a low-mileage example finished in the iconic Isle of Man Blue with black leather interior. It's been maintained under full BMW dealer service plan at BMW Midrand, with a clean service history book. The car features the Competition package which includes upgraded suspension, Adaptive M suspension, and M xDrive components making it track-ready while remaining comfortable for daily use. Carbon-fibre interior trim, Harman Kardon sound system, and heads-up display are all present. No accidents, no paint defects — immaculate condition inside and out.
        </div>
        <div class="desc-block__more" id="descToggle">Read more <i class="fa-solid fa-chevron-down" style="font-size:10px;"></i></div>
      </div>

      <!-- Specs -->
      <div class="spec-section anim-up d4">
        <div class="spec-section__title">Key specifications</div>
        <div class="spec-table">
          <div class="spec-table__row">
            <span class="spec-table__key">Make</span>
            <span class="spec-table__val">BMW</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Model</span>
            <span class="spec-table__val">M3 Competition</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Year</span>
            <span class="spec-table__val">2023</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Mileage</span>
            <span class="spec-table__val">18 400 km</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Fuel type</span>
            <span class="spec-table__val">Petrol</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Transmission</span>
            <span class="spec-table__val">8-speed Automatic (M Steptronic)</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Drivetrain</span>
            <span class="spec-table__val">RWD (M xDrive optional)</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Body style</span>
            <span class="spec-table__val">Sedan (4-door)</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Engine</span>
            <span class="spec-table__val">3.0L Inline 6 Turbocharged</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Power output</span>
            <span class="spec-table__val highlight">375 kW (510 hp)</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Torque</span>
            <span class="spec-table__val">650 Nm</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">0–100 km/h</span>
            <span class="spec-table__val highlight">3.9 seconds</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Exterior colour</span>
            <span class="spec-table__val">Isle of Man Blue Metallic</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Interior</span>
            <span class="spec-table__val">Merino Black Leather</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Service plan</span>
            <span class="spec-table__val highlight">Full BMW service plan (active)</span>
          </div>
          <div class="spec-table__row">
            <span class="spec-table__key">Province</span>
            <span class="spec-table__val">Gauteng</span>
          </div>
        </div>
      </div>

      <!-- Dealer card -->
      <div class="spec-section anim-up">
        <div class="spec-section__title">Dealership</div>
        <div class="dealer-card">
          <div class="dealer-card__header">
            <div class="dealer-card__icon"><i class="fa-solid fa-building"></i></div>
            <div>
              <div class="dealer-card__name">BMW Midrand</div>
              <div class="dealer-card__loc"><i class="fa-solid fa-location-dot" style="color:var(--p);margin-right:4px;font-size:10px;"></i>Midrand, Gauteng &middot; Authorised Dealer</div>
            </div>
            <span class="badge-verified" style="margin-left:auto;"><i class="fa-solid fa-circle-check"></i> Verified</span>
          </div>
          <div class="dealer-card__stats">
            <div class="dealer-stat">
              <div class="dealer-stat__num">47</div>
              <div class="dealer-stat__lbl">Active listings</div>
            </div>
            <div class="dealer-stat">
              <div class="dealer-stat__num">182</div>
              <div class="dealer-stat__lbl">All-time deals</div>
            </div>
            <div class="dealer-stat">
              <div class="dealer-stat__num">4.8 ★</div>
              <div class="dealer-stat__lbl">Dealer rating</div>
            </div>
          </div>
          <p style="font-size:12px;color:var(--faint);">
            <i class="fa-solid fa-info-circle" style="color:var(--p);margin-right:4px;"></i>
            Enquiries go to this dealer via your SalesDesk broker. First-touch attribution is locked at the time you submit.
          </p>
        </div>
      </div>

      <!-- Related -->
      <div class="related anim-up">
        <div class="related__title">More from Sfiso's Desk</div>
        <div class="related-grid">
          <div class="rel-card">
            <div class="rel-card__img">
              <img src="https://images.unsplash.com/photo-1609521263047-f8f205293f24?w=400&auto=format" alt="">
            </div>
            <div class="rel-card__body">
              <div class="rel-card__name">Mercedes GLE 53 AMG</div>
              <div class="rel-card__price">R 2 180 000</div>
              <div class="rel-card__meta">2024 · 5 100 km · GP</div>
            </div>
          </div>
          <div class="rel-card">
            <div class="rel-card__img">
              <img src="https://images.unsplash.com/photo-1555215695-3004980ad54e?w=400&auto=format" alt="">
            </div>
            <div class="rel-card__body">
              <div class="rel-card__name">BMW iX3 Inspiring</div>
              <div class="rel-card__price">R 1 250 000</div>
              <div class="rel-card__meta">2024 · 3 200 km · GP · EV</div>
            </div>
          </div>
          <div class="rel-card">
            <div class="rel-card__img">
              <img src="https://images.unsplash.com/photo-1617814076367-b759c7d7e738?w=400&auto=format" alt="">
            </div>
            <div class="rel-card__body">
              <div class="rel-card__name">Audi Q8 55 TFSI</div>
              <div class="rel-card__price">R 1 980 000</div>
              <div class="rel-card__meta">2023 · 14 500 km · GP</div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /left column -->

    <!-- RIGHT COLUMN — sticky enquiry card -->
    <div>
      <div class="enquiry-sticky anim-up d2">
        <div class="enquiry-card">
          <div class="enquiry-card__head">
            <div class="enquiry-card__price">R 1 650 000</div>
            <div class="enquiry-card__pm">Estimated ~R 28 400 / month</div>
            <div class="enquiry-card__commission">
              <span class="enquiry-card__commission-label">Broker commission:</span>
              <span class="enquiry-card__commission-val">R 16 500</span>
            </div>
          </div>
          <div class="enquiry-card__body">
            <div class="enquiry-broker">
              <div class="enquiry-broker__av">SD</div>
              <div>
                <div class="enquiry-broker__name">Listed by Sfiso Dlamini</div>
                <div class="enquiry-broker__sub">Verified broker · Gauteng · 4.9 ★</div>
              </div>
            </div>
            <form id="enquiryForm" onsubmit="submitEnquiry(event)">
              <label class="form-label">Full name</label>
              <input type="text" class="form-input" placeholder="e.g. Sipho Mokoena" required>
              <label class="form-label">Cell number</label>
              <input type="tel" class="form-input" placeholder="082 xxx xxxx" required>
              <label class="form-label">Email</label>
              <input type="email" class="form-input" placeholder="your@email.co.za" required>
              <label class="form-label">Buying timeline</label>
              <select class="form-input">
                <option value="">Select timeline…</option>
                <option>Within 30 days</option>
                <option>1–3 months</option>
                <option>Just exploring</option>
              </select>
              <label class="form-label">Message (optional)</label>
              <textarea class="form-input" rows="2" placeholder="Any specific questions about this car…"></textarea>
              <button type="submit" class="form-submit">
                <i class="fa-solid fa-paper-plane"></i> Submit Enquiry
              </button>
            </form>
            <div class="form-or">or</div>
            <button class="form-whatsapp">
              <i class="fab fa-whatsapp" style="font-size:15px;"></i> WhatsApp Sfiso directly
            </button>
            <div class="form-attribution">
              <span><i class="fa-solid fa-shield-halved"></i> Commission protected by platform</span>
              <span><i class="fa-solid fa-lock"></i> Your enquiry locks broker attribution</span>
              <span><i class="fa-solid fa-clock"></i> Response expected within 24 hours</span>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /detail-grid -->
</div><!-- /page -->

<script>
  // Gallery
  function setImg(thumb, src, num) {
    document.querySelectorAll('.gallery__thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
    document.getElementById('mainImgEl').src = src;
    document.querySelector('.gallery__photo-count').innerHTML = `<i class="fa-regular fa-image" style="margin-right:5px;"></i>${num} / 5`;
  }

  // Description toggle
  let descExpanded = false;
  document.getElementById('descToggle').addEventListener('click', function() {
    descExpanded = !descExpanded;
    const el = document.getElementById('descText');
    if (descExpanded) {
      el.classList.remove('clamped');
      this.innerHTML = 'Show less <i class="fa-solid fa-chevron-up" style="font-size:10px;"></i>';
    } else {
      el.classList.add('clamped');
      this.innerHTML = 'Read more <i class="fa-solid fa-chevron-down" style="font-size:10px;"></i>';
    }
  });

  // Wishlist button
  let wishlisted = false;
  document.getElementById('wishlistBtn').addEventListener('click', function() {
    wishlisted = !wishlisted;
    this.classList.toggle('active', wishlisted);
    this.querySelector('i').className = wishlisted ? 'fa-solid fa-heart' : 'fa-regular fa-heart';
  });

  // Enquiry submit
  function submitEnquiry(e) {
    e.preventDefault();
    alert('✅ Enquiry submitted to Sfiso\'s Auto Desk for the BMW M3 Competition.\n\nSfiso will contact you within 24 hours. Attribution locked.');
    e.target.reset();
  }

  /* Browse Cars dropdown */
  const browseBtn   = document.getElementById('browseBtn');
  const browsePanel = document.getElementById('browsePanel');
  function toggleBrowse(force) {
    const open = force !== undefined ? force : !browsePanel.classList.contains('open');
    browsePanel.classList.toggle('open', open);
    browseBtn.classList.toggle('open', open);
    browseBtn.setAttribute('aria-expanded', open);
  }
  browseBtn.addEventListener('click', e => { e.stopPropagation(); toggleBrowse(); });
  document.addEventListener('click', e => {
    if (!document.getElementById('browseMenu').contains(e.target)) toggleBrowse(false);
  });

  /* My Account dropdown */
  const accountBtn      = document.getElementById('accountBtn');
  const accountDropdown = document.getElementById('accountDropdown');
  function toggleDropdown(force) {
    const open = force !== undefined ? force : !accountDropdown.classList.contains('open');
    accountDropdown.classList.toggle('open', open);
    accountBtn.classList.toggle('open', open);
    accountBtn.setAttribute('aria-expanded', open);
  }
  accountBtn.addEventListener('click', e => { e.stopPropagation(); toggleDropdown(); });
  document.addEventListener('click', e => {
    if (!document.getElementById('accountMenu').contains(e.target)) toggleDropdown(false);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { toggleDropdown(false); toggleBrowse(false); }
  });
</script>
</body>
</html>