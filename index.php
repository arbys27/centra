<?php
session_start();
if (isset($_SESSION['user_id'])) {
		header("Location: dashboard.php");
		exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="CENTRA is the office management system of the 14th Central Student Council, PUP Binan Campus.">
<title>CENTRA | 14th Central Student Council</title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<style>
	:root{
		--ink:#111;
		--ink-soft:#252525;
		--paper:#f7f7f4;
		--paper-2:#ecece8;
		--card:#ffffff;
		--line:rgba(17,17,17,.12);
		--muted:#666;
		--ease:cubic-bezier(.16,1,.3,1);
	}
	*{box-sizing:border-box;}
	html{scroll-behavior:smooth;scroll-padding-top:96px;}
	body{margin:0;background:var(--paper);color:var(--ink);font-family:"Trebuchet MS","Segoe UI",sans-serif;overflow-x:hidden;-webkit-font-smoothing:antialiased;}

	/* ---------- Floating nav ---------- */
	.site-nav{position:sticky;top:0;z-index:50;padding:14px 0 0;background:transparent;}
	.nav-shell{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.55rem .6rem .55rem 1rem;background:rgba(247,247,244,.78);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border:1px solid var(--line);border-radius:999px;box-shadow:0 8px 30px rgba(17,17,17,.06);}
	.brand{display:flex;align-items:center;gap:.7rem;color:var(--ink);text-decoration:none;}
	.brand-mark{width:38px;height:38px;object-fit:cover;border:2px solid var(--ink);border-radius:50%;}
	.brand-text{display:flex;flex-direction:column;line-height:1.1;}
	.brand-text strong{font-size:1.02rem;letter-spacing:.22em;font-weight:800;}
	.brand-text small{font-size:.66rem;color:var(--muted);letter-spacing:.02em;margin-top:2px;}
	.nav-links{display:flex;align-items:center;gap:.25rem;margin:0;padding:0;list-style:none;}
	.nav-links a.link{position:relative;display:inline-block;padding:.5rem .9rem;color:var(--ink);font-size:.82rem;font-weight:700;text-decoration:none;border-radius:999px;transition:background .3s var(--ease);}
	.nav-links a.link:hover{background:rgba(17,17,17,.06);}
	.nav-toggle{display:none;width:42px;height:42px;border:1px solid var(--line);background:transparent;border-radius:50%;align-items:center;justify-content:center;cursor:pointer;}
	.nav-toggle span,.nav-toggle span::before,.nav-toggle span::after{display:block;width:16px;height:2px;background:var(--ink);border-radius:2px;position:relative;transition:transform .3s var(--ease);}
	.nav-toggle span::before,.nav-toggle span::after{content:"";position:absolute;left:0;}
	.nav-toggle span::before{top:-5px;}.nav-toggle span::after{top:5px;}

	/* ---------- Buttons ---------- */
	.btn-ink{display:inline-flex;align-items:center;gap:.55rem;background:var(--ink);border:1px solid var(--ink);color:#fff;border-radius:999px;padding:.8rem 1.4rem;font-weight:700;font-size:.9rem;text-decoration:none;transition:transform .35s var(--ease),background .35s var(--ease),box-shadow .35s var(--ease);}
	.btn-ink:hover{background:#393939;color:#fff;transform:translateY(-2px);box-shadow:0 12px 26px rgba(17,17,17,.2);}
	.btn-ink .arrow{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#fff;color:var(--ink);font-size:.8rem;transition:transform .35s var(--ease);}
	.btn-ink:hover .arrow{transform:translateX(3px);}
	.btn-ink.sm{padding:.55rem 1.1rem;font-size:.8rem;}
	.btn-ghost{display:inline-flex;align-items:center;border:1px solid var(--ink);color:var(--ink);background:transparent;border-radius:999px;padding:.8rem 1.4rem;font-weight:700;font-size:.9rem;text-decoration:none;transition:all .35s var(--ease);}
	.btn-ghost:hover{background:var(--ink);color:#fff;transform:translateY(-2px);}

	/* ---------- Hero ---------- */
	.hero{position:relative;isolation:isolate;margin-top:-62px;padding:10rem 0 6rem;background:radial-gradient(circle at 82% 22%,rgba(0,0,0,.09),transparent 26rem),linear-gradient(120deg,#f7f7f4,#ecece8,#f7f7f4);background-size:auto,220% 220%;animation:ambient-shift 16s ease-in-out infinite alternate;}
	.hero::before{content:"";position:absolute;z-index:-1;inset:0;background-image:linear-gradient(rgba(17,17,17,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(17,17,17,.04) 1px,transparent 1px);background-size:44px 44px;mask-image:radial-gradient(ellipse at 50% 30%,#000 30%,transparent 80%);-webkit-mask-image:radial-gradient(ellipse at 50% 30%,#000 30%,transparent 80%);}
	.pill{display:inline-flex;align-items:center;gap:.6rem;padding:.4rem .9rem .4rem .5rem;margin-bottom:1.5rem;background:rgba(255,255,255,.7);border:1px solid var(--line);border-radius:999px;font-size:.74rem;font-weight:700;color:var(--ink-soft);backdrop-filter:blur(8px);animation:hero-reveal .8s var(--ease) both;}
	.pill b{display:inline-block;padding:.18rem .6rem;background:var(--ink);color:#fff;border-radius:999px;font-size:.66rem;letter-spacing:.16em;}
	.hero h1{max-width:820px;font-size:clamp(3rem,7.4vw,6.6rem);line-height:.95;letter-spacing:-.07em;font-weight:800;margin:0;animation:hero-reveal .9s .08s var(--ease) both;}
	.hero h1 span{display:block;color:transparent;-webkit-text-stroke:1.5px var(--ink);}
	.hero-copy{max-width:540px;color:var(--muted);font-size:1.05rem;line-height:1.7;animation:hero-reveal .9s .16s var(--ease) both;}
	.hero-actions{animation:hero-reveal .9s .24s var(--ease) both;}
	.chip-row{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:2.2rem;animation:hero-reveal .9s .32s var(--ease) both;}
	.chip{padding:.4rem .85rem;font-size:.74rem;font-weight:700;color:var(--ink-soft);background:transparent;border:1px solid var(--line);border-radius:999px;}

	.seal-stage{position:relative;display:flex;align-items:center;justify-content:center;min-height:420px;animation:hero-reveal 1s .2s var(--ease) both;}
	.seal-stage::before{content:"";position:absolute;width:340px;height:340px;border:1px solid var(--line);border-radius:50%;animation:orbit 18s linear infinite;}
	.seal-stage::after{content:"";position:absolute;width:410px;height:410px;border:1px dashed rgba(17,17,17,.2);border-radius:50%;animation:orbit 28s linear infinite reverse;}
	.hero-seal{position:relative;z-index:1;width:min(66vw,270px);height:min(66vw,270px);object-fit:cover;border:8px solid var(--ink);border-radius:50%;box-shadow:0 30px 60px rgba(0,0,0,.22);}
	.float-card{position:absolute;z-index:2;display:flex;align-items:center;gap:.6rem;padding:.6rem .9rem;background:rgba(255,255,255,.82);border:1px solid rgba(255,255,255,.9);border-radius:14px;backdrop-filter:blur(10px);box-shadow:0 14px 34px rgba(17,17,17,.12);font-size:.74rem;font-weight:700;animation:float 6s ease-in-out infinite;}
	.float-card i{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--ink);}
	.float-card small{display:block;color:var(--muted);font-weight:600;font-size:.66rem;}
	.float-card.a{top:14%;left:2%;}
	.float-card.b{bottom:16%;right:0;animation-delay:-3s;}

	/* ---------- Sections ---------- */
	.section-pad{padding:6.5rem 0;}
	.section-kicker{display:inline-flex;align-items:center;gap:.6rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.16em;font-weight:700;color:var(--muted);}
	.section-kicker::before{content:"";width:26px;height:2px;background:currentColor;}
	.section-title{font-size:clamp(2rem,4vw,3.4rem);letter-spacing:-.06em;line-height:1;margin:0;font-weight:800;}

	/* Bento services */
	.bento{display:grid;grid-template-columns:repeat(12,1fr);gap:1rem;}
	.tile{position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;min-height:250px;padding:1.7rem;background:var(--card);border:1px solid var(--line);border-radius:1.4rem;transition:transform .45s var(--ease),box-shadow .45s var(--ease),border-color .45s var(--ease);}
	.tile:hover{transform:translateY(-6px);border-color:rgba(17,17,17,.3);box-shadow:0 26px 46px rgba(17,17,17,.12);}
	.tile.t1{grid-column:span 7;background:var(--ink);color:#fff;border-color:var(--ink);}
	.tile.t2{grid-column:span 5;}
	.tile.t3{grid-column:span 5;}
	.tile.t4{grid-column:span 7;background:var(--paper-2);}
	.tile .num{display:inline-flex;align-items:center;justify-content:center;width:2.4rem;height:2.4rem;border-radius:50%;background:var(--ink);color:#fff;font-size:.76rem;font-weight:700;}
	.tile.t1 .num{background:#fff;color:var(--ink);}
	.tile h3{font-size:1.45rem;letter-spacing:-.03em;font-weight:800;margin:2.6rem 0 .5rem;}
	.tile p{margin:0;color:var(--muted);line-height:1.65;max-width:44ch;}
	.tile.t1 p{color:#bdbdbd;}
	.tile::after{content:"";position:absolute;right:-60px;top:-60px;width:180px;height:180px;border-radius:50%;border:1px solid rgba(17,17,17,.08);}
	.tile.t1::after{border-color:rgba(255,255,255,.14);}

	/* Access band */
	.band-wrap{padding:0 0 6rem;}
	.access-band{position:relative;overflow:hidden;padding:4rem;background:var(--ink);color:#fff;border-radius:2rem;}
	.access-band::before{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.05) 1px,transparent 1px);background-size:44px 44px;mask-image:radial-gradient(ellipse at 100% 0%,#000,transparent 70%);-webkit-mask-image:radial-gradient(ellipse at 100% 0%,#000,transparent 70%);}
	.access-band > *{position:relative;}
	.access-band .section-kicker,.access-band p{color:#bdbdbd;}
	.access-band .btn-ink{background:#fff;color:var(--ink);border-color:#fff;}
	.access-band .btn-ink:hover{background:#e6e6e2;}

	footer{border-top:1px solid var(--line);color:var(--muted);font-size:.78rem;padding:1.5rem 0;}
	footer strong{color:var(--ink);letter-spacing:.2em;}

	/* Scroll reveal */
	.reveal{opacity:0;transform:translateY(28px);transition:opacity .8s var(--ease),transform .8s var(--ease);}
	.reveal.in{opacity:1;transform:none;}

	@keyframes hero-reveal{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
	@keyframes ambient-shift{from{background-position:0 50%;}to{background-position:100% 50%;}}
	@keyframes orbit{to{transform:rotate(360deg);}}
	@keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-10px);}}

	@media(max-width:991.98px){
		.nav-toggle{display:inline-flex;}
		.nav-links{position:absolute;top:calc(100% + 8px);left:0;right:0;flex-direction:column;align-items:stretch;padding:.6rem;background:rgba(247,247,244,.97);border:1px solid var(--line);border-radius:1.2rem;box-shadow:0 20px 40px rgba(17,17,17,.12);display:none;}
		.nav-links.open{display:flex;}
		.nav-links a.link{padding:.8rem 1rem;}
		.nav-links .btn-ink{justify-content:center;}
		.nav-shell{position:relative;}
	}
	@media(max-width:767.98px){
		.hero{padding:8rem 0 3.5rem;}
		.seal-stage{min-height:320px;margin-top:2rem;}
		.seal-stage::before{width:260px;height:260px;}.seal-stage::after{width:310px;height:310px;}
		.float-card.a{left:0;}.float-card.b{right:0;}
		.section-pad{padding:4.5rem 0;}
		.tile.t1,.tile.t2,.tile.t3,.tile.t4{grid-column:span 12;min-height:210px;}
		.access-band{padding:2.4rem 1.6rem;border-radius:1.5rem;}
	}
	@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;scroll-behavior:auto!important;transition-duration:.01ms!important;}.reveal{opacity:1;transform:none;}}
</style>
</head>
<body>
<header class="site-nav">
	<div class="container">
		<nav class="nav-shell" aria-label="Main navigation">
			<a class="brand" href="index.php">
				<img class="brand-mark" src="assets/img/629336028_1332709998878542_5484852480615400786_n.jpg" alt="Council seal">
				<span class="brand-text"><strong>CENTRA</strong><small>14th Central Student Council &middot; PUP Biñan</small></span>
			</a>
			<button class="nav-toggle" id="navToggle" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="navLinks"><span></span></button>
			<ul class="nav-links" id="navLinks">
				<li><a class="link" href="#services">Services</a></li>
				<li><a class="link" href="#about">About</a></li>
				<li><a class="btn-ink sm" href="login.php">Sign in <span class="arrow" aria-hidden="true">&#8594;</span></a></li>
			</ul>
		</nav>
	</div>
</header>

<main>
	<section class="hero" aria-labelledby="hero-title">
		<div class="container">
			<div class="row align-items-center g-5">
				<div class="col-lg-7">
					<div class="pill"><b>CENTRA</b> Office Management System</div>
					<h1 id="hero-title">Move campus service <span>forward.</span></h1>
					<p class="hero-copy mt-4 mb-0">One calm, connected workspace for the everyday work of the 14th Central Student Council, from student support to meaningful campus programs.</p>
					<div class="hero-actions d-flex flex-wrap gap-3 mt-4">
						<a class="btn-ink" href="login.php">Enter CENTRA <span class="arrow" aria-hidden="true">&#8594;</span></a>
						<a class="btn-ghost" href="#services">Explore services</a>
					</div>
					<div class="chip-row" aria-label="Modules">
						<span class="chip">Printan ng Bayan</span>
						<span class="chip">ISKOLEND</span>
						<span class="chip">Officer Attendance</span>
						<span class="chip">Analytics &amp; Reports</span>
					</div>
				</div>
				<div class="col-lg-5">
					<div class="seal-stage" aria-label="Central Student Council seal">
						<img class="hero-seal" src="assets/img/629336028_1332709998878542_5484852480615400786_n.jpg" alt="14th Central Student Council, PUP Biñan Campus">
						<div class="float-card a" aria-hidden="true"><i></i><div>Scan &amp; verify<small>Student ID check</small></div></div>
						<div class="float-card b" aria-hidden="true"><i></i><div>Logged<small>Every request, tracked</small></div></div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<section class="section-pad" id="services" aria-labelledby="services-title">
		<div class="container">
			<div class="row align-items-end g-4 mb-5 reveal">
				<div class="col-lg-7"><div class="section-kicker mb-3">A better rhythm for campus work</div><h2 class="section-title" id="services-title">Everything in one place.</h2></div>
				<div class="col-lg-5"><p class="text-secondary mb-0">Built for quick decisions, clear records, and more time spent serving the community.</p></div>
			</div>
			<div class="bento">
				<article class="tile t1 reveal"><span class="num">01</span><div><h3>Printan ng Bayan</h3><p>Scan a student ID, log print, xerox and scan requests, and let pricing compute itself. Every request leaves a clear record.</p></div></article>
				<article class="tile t2 reveal"><span class="num">02</span><div><h3>ISKOLEND</h3><p>Lend and track school items with due dates, return conditions and borrowing limits.</p></div></article>
				<article class="tile t3 reveal"><span class="num">03</span><div><h3>Officer attendance</h3><p>Keep officer attendance organized and easy to review.</p></div></article>
				<article class="tile t4 reveal"><span class="num">04</span><div><h3>Useful insight</h3><p>Turn attendance and service activity into clear reports that help the council plan its next move.</p></div></article>
			</div>
		</div>
	</section>

	<section class="band-wrap" id="about" aria-labelledby="about-title">
		<div class="container">
			<div class="access-band reveal">
				<div class="row align-items-center g-4">
					<div class="col-lg-8"><div class="section-kicker mb-3">The 14th Central Student Council</div><h2 class="section-title" id="about-title">Service with structure. Purpose with momentum.</h2><p class="mt-3 mb-0">CENTRA is the digital home for the people, programs, and progress of PUP Biñan Campus.</p></div>
					<div class="col-lg-4 text-lg-end"><a class="btn-ink" href="login.php">Access CENTRA <span class="arrow" aria-hidden="true">&#8594;</span></a></div>
				</div>
			</div>
		</div>
	</section>
</main>

<footer><div class="container d-flex flex-column flex-md-row justify-content-between gap-2"><span><strong>CENTRA</strong> &nbsp;14th Central Student Council &middot; PUP Biñan Campus</span><span>Office Management System</span></div></footer>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
(function () {
	'use strict';
	var toggle = document.getElementById('navToggle');
	var links = document.getElementById('navLinks');
	toggle.addEventListener('click', function () {
		var open = links.classList.toggle('open');
		toggle.setAttribute('aria-expanded', String(open));
	});
	links.addEventListener('click', function (e) {
		if (e.target.tagName === 'A') { links.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); }
	});
	var items = document.querySelectorAll('.reveal');
	if (!('IntersectionObserver' in window)) { items.forEach(function (el) { el.classList.add('in'); }); return; }
	var io = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) { entry.target.classList.add('in'); io.unobserve(entry.target); }
		});
	}, { threshold: .12 });
	items.forEach(function (el, i) { el.style.transitionDelay = (i % 4) * 70 + 'ms'; io.observe(el); });
})();
</script>
</body>
</html>