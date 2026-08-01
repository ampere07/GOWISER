*, *::before, *::after { box-sizing: border-box; }

:root {
    /* Go Wiser Corporation palette */
    --auth-green:       #57B446;
    --auth-green-dk:    #347026;
    --auth-blue:        #2C9AD2;
    --auth-blue-dk:     #16509E;
    --auth-primary:     #57B446;
    --auth-primary-dk:  #347026;
    --auth-radius:      14px;
    --auth-input-h:     48px;
    --auth-shadow:      0 24px 64px rgba(0,0,0,.14), 0 4px 20px rgba(0,0,0,.08);
}

html, body { height: 100%; margin: 0; padding: 0; }

.auth-body {
    background: #f0f2f5;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    min-height: 100vh;
}

/* ════════════════════════════════════════════════════════════
   PAGE WRAPPER
════════════════════════════════════════════════════════════ */
.auth-page {
    display: flex;
    min-height: 100vh;
}

/* ════════════════════════════════════════════════════════════
   LEFT BRAND PANEL — deep black with green/blue lights
════════════════════════════════════════════════════════════ */
.auth-brand {
    display: none;
    flex-direction: column;
    justify-content: space-between;
    width: 44%;
    min-height: 100vh;
    background: #020905;
    position: relative;
    overflow: hidden;
    padding: 3rem 3rem 2rem;
}

/* ── Dot grid overlay ─────────────────────────────────────── */
.auth-brand::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        radial-gradient(circle, rgba(87,180,70,.1) 1px, transparent 1px);
    background-size: 28px 28px;
    pointer-events: none;
    z-index: 0;
}

/* ── Orbs (green + blue) ──────────────────────────────────── */
.auth-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(72px);
    pointer-events: none;
    will-change: transform, opacity;
}

.auth-orb-1 {
    width: 380px; height: 380px;
    background: radial-gradient(circle, rgba(87,180,70,.55) 0%, transparent 70%);
    top: -110px; left: -90px;
    animation: orbFloat1 9s ease-in-out infinite;
}
.auth-orb-2 {
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(44,154,210,.5) 0%, transparent 70%);
    bottom: 30px; right: -70px;
    animation: orbFloat2 11s ease-in-out infinite;
}
.auth-orb-3 {
    width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(22,80,158,.4) 0%, transparent 70%);
    top: 48%; left: 55%;
    transform: translate(-50%,-50%);
    animation: orbFloat3 7s ease-in-out infinite;
}
.auth-orb-4 {
    width: 160px; height: 160px;
    background: radial-gradient(circle, rgba(87,180,70,.3) 0%, transparent 70%);
    bottom: 25%; left: 5%;
    animation: orbFloat4 13s ease-in-out infinite;
}

@keyframes orbFloat1 {
    0%,100% { transform: translate(0,0) scale(1); opacity: .75; }
    33%      { transform: translate(40px,30px) scale(1.08); opacity: 1; }
    66%      { transform: translate(-20px,55px) scale(.95); opacity: .8; }
}
@keyframes orbFloat2 {
    0%,100% { transform: translate(0,0) scale(1); opacity: .6; }
    40%      { transform: translate(-50px,-40px) scale(1.1); opacity: .9; }
    70%      { transform: translate(20px,-60px) scale(.9); opacity: .7; }
}
@keyframes orbFloat3 {
    0%,100% { transform: translate(-50%,-50%) scale(1); opacity: .5; }
    50%      { transform: translate(-50%,-50%) scale(1.3); opacity: .85; }
}
@keyframes orbFloat4 {
    0%,100% { transform: translate(0,0); opacity: .4; }
    60%      { transform: translate(30px,-40px); opacity: .7; }
}

/* ── Light beams ──────────────────────────────────────────── */
.auth-beam {
    position: absolute;
    pointer-events: none;
}
.auth-beam-1 {
    top: 0; left: 20%;
    width: 1px; height: 100%;
    background: linear-gradient(to bottom,
        transparent 0%,
        rgba(87,180,70,.3) 20%,
        rgba(44,154,210,.18) 50%,
        transparent 100%);
    animation: beamSlide1 8s ease-in-out infinite;
}
.auth-beam-2 {
    top: 0; left: 60%;
    width: 1px; height: 100%;
    background: linear-gradient(to bottom,
        transparent 0%,
        rgba(44,154,210,.25) 30%,
        rgba(87,180,70,.12) 60%,
        transparent 100%);
    animation: beamSlide2 12s ease-in-out infinite;
}
@keyframes beamSlide1 {
    0%,100% { left: 20%; opacity: .6; }
    50%      { left: 35%; opacity: 1; }
}
@keyframes beamSlide2 {
    0%,100% { left: 60%; opacity: .4; }
    50%      { left: 45%; opacity: .85; }
}

/* ── Scan line ────────────────────────────────────────────── */
.auth-scan {
    position: absolute;
    left: 0; right: 0;
    height: 2px;
    background: linear-gradient(to right,
        transparent, rgba(87,180,70,.6), rgba(44,154,210,.7), transparent);
    animation: scanDown 6s ease-in-out infinite;
    pointer-events: none;
    filter: blur(1px);
}
@keyframes scanDown {
    0%   { top: -2px; opacity: 0; }
    5%   { opacity: 1; }
    95%  { opacity: .6; }
    100% { top: 100%; opacity: 0; }
}

/* ── Floating particles (JS-injected) ────────────────────── */
.auth-particle {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
    animation: particleRise linear infinite;
    will-change: transform, opacity;
}
@keyframes particleRise {
    0%   { transform: translateY(0) scale(1); opacity: 0; }
    10%  { opacity: 1; }
    90%  { opacity: .5; }
    100% { transform: translateY(-110vh) scale(.4); opacity: 0; }
}

/* ── Brand content ────────────────────────────────────────── */
.auth-brand-inner {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1.25rem;
    animation: authFadeUp .7s ease both;
}

/* ── Logo with rotating glow ring ────────────────────────── */
.auth-logo-ring {
    position: relative;
    width: 90px; height: 90px;
}
.auth-logo-ring::before {
    content: '';
    position: absolute;
    inset: -6px;
    border-radius: 26px;
    background: conic-gradient(
        from 0deg,
        rgba(87,180,70,0),
        rgba(87,180,70,.9),
        rgba(44,154,210,.9),
        rgba(22,80,158,.8),
        rgba(87,180,70,0)
    );
    animation: ringRotate 3.5s linear infinite;
    filter: blur(2px);
}
.auth-logo-ring::after {
    content: '';
    position: absolute;
    inset: -3px;
    border-radius: 24px;
    background: #020905;
}
@keyframes ringRotate {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

.auth-logo-wrap {
    position: relative;
    z-index: 1;
    width: 90px; height: 90px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(87,180,70,.2);
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px;
    backdrop-filter: blur(8px);
    box-shadow: 0 0 40px rgba(87,180,70,.2), inset 0 1px 0 rgba(255,255,255,.1);
}
.auth-logo {
    width: 100%; height: 100%;
    object-fit: contain;
    position: relative;
    z-index: 1;
}

.auth-brand-name {
    font-size: 1.9rem;
    font-weight: 800;
    color: #fff;
    margin: 0;
    letter-spacing: -.03em;
    line-height: 1.15;
    text-shadow: 0 0 30px rgba(87,180,70,.4);
}

.auth-brand-desc {
    color: rgba(255,255,255,.45);
    font-size: .875rem;
    margin: 0;
    line-height: 1.7;
    max-width: 100%;
}

/* ── Glowing badge dots ───────────────────────────────────── */
.auth-brand-badges {
    display: flex;
    flex-direction: column;
    gap: .75rem;
    margin-top: 1.5rem;
}
.auth-brand-badge {
    display: flex;
    align-items: center;
    gap: .6rem;
    color: rgba(255,255,255,.55);
    font-size: .82rem;
}
.auth-brand-badge-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}
.auth-brand-badge-dot.blue   { background: #2C9AD2; box-shadow: 0 0 8px rgba(44,154,210,.9); }
.auth-brand-badge-dot.cyan   { background: #57B446; box-shadow: 0 0 8px rgba(87,180,70,.9); }
.auth-brand-badge-dot.purple { background: #16509E; box-shadow: 0 0 8px rgba(22,80,158,.9); }

.auth-brand-footer {
    position: relative;
    z-index: 2;
    color: rgba(255,255,255,.25);
    font-size: .78rem;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.auth-brand-footer::before {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,.08);
    max-width: 60px;
}

/* ════════════════════════════════════════════════════════════
   RIGHT FORM PANEL
════════════════════════════════════════════════════════════ */
.auth-form-panel {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    min-height: 100vh;
    background: linear-gradient(135deg, #f4fbf3 0%, #eef7f0 50%, #f0f7f4 100%);
    position: relative;
}
.auth-form-panel::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 50% 40% at 80% 20%, rgba(87,180,70,.06) 0%, transparent 70%),
        radial-gradient(ellipse 40% 50% at 20% 80%, rgba(44,154,210,.05) 0%, transparent 70%);
    pointer-events: none;
}

/* ── Card ─────────────────────────────────────────────────── */
.auth-card {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 420px;
    background: rgba(255,255,255,.97);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    box-shadow: var(--auth-shadow), 0 0 0 1px rgba(255,255,255,.8);
    padding: 2.5rem 2rem;
    animation: authFadeUp .5s ease both;
    border: 1px solid rgba(87,180,70,.12);
}

/* ── Mobile logo ──────────────────────────────────────────── */
.auth-mobile-logo {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #e9ecef;
}
.auth-mobile-logo img {
    width: 38px; height: 38px;
    object-fit: contain;
    border-radius: 8px;
}
.auth-mobile-logo span {
    font-weight: 700;
    font-size: 1rem;
    color: #1a1a2e;
    letter-spacing: -.01em;
}

/* ── Card header ──────────────────────────────────────────── */
.auth-card-header { margin-bottom: 1.75rem; }

.auth-icon-circle {
    width: 54px; height: 54px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 1rem;
    position: relative;
}
.auth-icon-circle::after {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 20px;
    opacity: .25;
    animation: iconPulse 2.5s ease-in-out infinite;
}
.auth-icon-primary  { background: rgba(87,180,70,.12);  color: #57B446; }
.auth-icon-primary::after  { box-shadow: 0 0 0 4px rgba(87,180,70,.35); }
.auth-icon-warning  { background: rgba(245,158,11,.1);  color: #d97706; }
.auth-icon-warning::after  { box-shadow: 0 0 0 4px rgba(245,158,11,.3); }
.auth-icon-success  { background: rgba(44,154,210,.1);  color: #2C9AD2; }
.auth-icon-success::after  { box-shadow: 0 0 0 4px rgba(44,154,210,.3); }

@keyframes iconPulse {
    0%,100% { opacity: .2; transform: scale(1); }
    50%      { opacity: .5; transform: scale(1.1); }
}

.auth-title {
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 .3rem;
    letter-spacing: -.025em;
}
.auth-subtitle {
    color: #64748b;
    font-size: .875rem;
    margin: 0;
}

/* ── Alerts ───────────────────────────────────────────────── */
.auth-alert {
    display: flex;
    align-items: flex-start;
    gap: .6rem;
    padding: .8rem 1rem;
    border-radius: 12px;
    font-size: .875rem;
    margin-bottom: 1.25rem;
    line-height: 1.5;
    animation: authFadeUp .3s ease both;
}
.auth-alert i { font-size: 1rem; flex-shrink: 0; margin-top: .05rem; }
.auth-alert-danger  { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
.auth-alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.auth-alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.auth-alert code {
    background: rgba(0,0,0,.07);
    padding: .15rem .45rem;
    border-radius: 5px;
    font-size: .78rem;
    word-break: break-all;
    color: inherit;
    display: block;
    margin-top: .35rem;
}

/* ── Fields ───────────────────────────────────────────────── */
.auth-field { margin-bottom: 1.15rem; }

.auth-label {
    display: block;
    font-size: .75rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: .45rem;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.auth-input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.auth-input-icon {
    position: absolute;
    left: 14px;
    color: #94a3b8;
    font-size: 1rem;
    pointer-events: none;
    z-index: 1;
    transition: color .25s;
}

.auth-input {
    width: 100%;
    height: var(--auth-input-h);
    padding: 0 3rem 0 2.8rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-size: .9375rem;
    color: #0f172a;
    background: #f8fafc;
    outline: none;
    transition: border-color .25s, background .25s, box-shadow .25s;
    -webkit-appearance: none;
}
.auth-input::placeholder { color: #94a3b8; }
.auth-input:focus {
    border-color: #57B446;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(87,180,70,.12), 0 1px 3px rgba(0,0,0,.06);
}
.auth-input-group:focus-within .auth-input-icon { color: #57B446; }

.auth-toggle-btn {
    position: absolute;
    right: 12px;
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 1rem;
    cursor: pointer;
    padding: .25rem;
    display: flex;
    align-items: center;
    transition: color .2s;
    z-index: 1;
}
.auth-toggle-btn:hover { color: #475569; }

/* ── Links ────────────────────────────────────────────────── */
.auth-link-sm {
    font-size: .8rem;
    color: #57B446;
    text-decoration: none;
    font-weight: 600;
    transition: color .2s;
}
.auth-link-sm:hover { color: #347026; text-decoration: underline; }

.auth-back-link {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .85rem;
    color: #64748b;
    text-decoration: none;
    margin-bottom: 1.5rem;
    transition: color .2s, gap .2s;
    font-weight: 500;
}
.auth-back-link:hover { color: #0f172a; gap: .5rem; }

/* ── Buttons ──────────────────────────────────────────────── */
.auth-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 50px;
    border: none;
    border-radius: 12px;
    font-size: .9375rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all .22s cubic-bezier(.4,0,.2,1);
    gap: .5rem;
    letter-spacing: .01em;
}

/* Primary = Go Wiser green */
.auth-btn-primary {
    background: linear-gradient(135deg, #57B446 0%, #347026 100%);
    color: #fff;
    box-shadow: 0 2px 12px rgba(87,180,70,.35);
}
.auth-btn-primary:hover {
    background: linear-gradient(135deg, #4ca03c 0%, #296020 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(87,180,70,.5);
    color: #fff;
}
.auth-btn-primary:active { transform: translateY(0); box-shadow: 0 2px 8px rgba(87,180,70,.3); }

.auth-btn-outline {
    background: transparent;
    color: #374151;
    border: 1.5px solid #e2e8f0;
}
.auth-btn-outline:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
    text-decoration: none;
}

/* Success = Cerulean blue */
.auth-btn-success {
    background: linear-gradient(135deg, #2C9AD2 0%, #16509E 100%);
    color: #fff;
    box-shadow: 0 2px 12px rgba(44,154,210,.3);
}
.auth-btn-success:hover {
    background: linear-gradient(135deg, #2589bc 0%, #124590 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(44,154,210,.45);
    color: #fff;
}

.auth-btn-google {
    background: #fff;
    color: #374151;
    border: 1.5px solid #e2e8f0;
    font-weight: 600;
    font-size: .9rem;
}
.auth-btn-google:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 3px 12px rgba(0,0,0,.08);
}

/* ── Divider ──────────────────────────────────────────────── */
.auth-divider {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin: 1.25rem 0;
    color: #94a3b8;
    font-size: .78rem;
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
}
.auth-divider::before, .auth-divider::after {
    content: ''; flex: 1; height: 1px;
    background: linear-gradient(to right, transparent, #e2e8f0, transparent);
}

/* ── Password strength ────────────────────────────────────── */
.auth-strength-bar {
    height: 5px;
    border-radius: 3px;
    background: #e2e8f0;
    margin-top: .5rem;
    overflow: hidden;
}
.auth-strength-fill {
    height: 100%;
    border-radius: 3px;
    transition: width .35s cubic-bezier(.4,0,.2,1), background .35s;
}
.auth-strength-label {
    font-size: .73rem;
    color: #94a3b8;
    margin-top: .3rem;
    font-weight: 500;
}

/* ── Info box ─────────────────────────────────────────────── */
.auth-info-box {
    background: #f4fbf3;
    border: 1px solid rgba(87,180,70,.2);
    border-radius: 12px;
    padding: .9rem 1rem;
    font-size: .85rem;
    color: #475569;
    margin-bottom: 1.25rem;
    line-height: 1.6;
}
.auth-info-box strong { color: #1e293b; }

/* ── Success state ────────────────────────────────────────── */
.auth-success-state {
    text-align: center;
    padding: .75rem 0;
    animation: authFadeUp .4s ease both;
}
.auth-success-icon {
    width: 72px; height: 72px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2rem;
    position: relative;
    background: rgba(87,180,70,.1);
    color: #57B446;
    box-shadow: 0 0 0 8px rgba(87,180,70,.08), 0 0 0 16px rgba(87,180,70,.04);
}
.auth-success-icon.blue {
    background: rgba(44,154,210,.1);
    color: #2C9AD2;
    box-shadow: 0 0 0 8px rgba(44,154,210,.08), 0 0 0 16px rgba(44,154,210,.04);
}
.auth-success-title {
    font-size: 1.3rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: .5rem;
    letter-spacing: -.02em;
}
.auth-success-text {
    color: #64748b;
    font-size: .875rem;
    margin-bottom: 1.75rem;
    line-height: 1.7;
}

/* ════════════════════════════════════════════════════════════
   ANIMATIONS
════════════════════════════════════════════════════════════ */
@keyframes authFadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ════════════════════════════════════════════════════════════
   RESPONSIVE
════════════════════════════════════════════════════════════ */
@media (min-width: 768px) {
    .auth-brand       { display: flex; }
    .auth-mobile-logo { display: none; }
    .auth-card        { padding: 3rem 2.5rem; }
}

@media (max-width: 767px) {
    .auth-body        { background: #fff; }
    .auth-form-panel  { background: #fff; padding: 0; align-items: flex-start; }
    .auth-card        { box-shadow: none; border-radius: 0; border: none;
                        background: #fff; padding: 2rem 1.25rem; min-height: 100vh; }
}
