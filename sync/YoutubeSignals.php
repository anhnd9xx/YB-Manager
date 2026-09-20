<?php
declare(strict_types=1);
/**
 * YoutubeSignals - SELECTOR + SIGNAL TAP TRUNG (§50).
 * Khong rai selector o nhieu file. Moi signal co fallback.
 * Uu tien structural/state hon raw text; text chi la one evidence (§52-§53).
 */
class YoutubeSignals
{
    /** Avatar / account identity (moi cai la fallback cho nhau). */
    public const AVATAR = [
        'button#avatar-btn',
        '#avatar-btn',
        'ytd-topbar-menu-button-renderer button#avatar-btn',
        'button[aria-label*="Account" i]',
        'button[aria-label*="Tài khoản" i]',
        'yt-img-shadow#avatar img',
        '#masthead button[aria-haspopup="true"]',
    ];

    /** Sign-in CTA (nhieu locale + cau truc). Chi la ONE evidence. */
    public const SIGNIN_CTA = [
        'a[href*="accounts.google.com/ServiceLogin"]',
        'a[href*="accounts.youtube.com/accounts/SetSID" i]',
        'ytd-button-renderer a[href*="signin" i]',
        'ytd-masthead a[href*="signin" i]',
        '#masthead a[href*="signin" i]',
        'a.yt-spec-button-shape-next--call-to-action',
    ];

    /** Text bien the theo locale (chi dung kem structural, khong dung mot minh). */
    public const SIGNIN_TEXT = [
        'sign in', 'log in', 'đăng nhập', 'dang nhap',
    ];

    public const CREATE_CHANNEL_TEXT = [
        'create a channel', 'create channel', 'tạo kênh', 'tao kenh',
    ];

    /**
     * JS thu evidence YOUTUBE trong 1 evaluate duy nhat (it CDP roundtrip).
     * Tra ve dict: av, avCount, signinCta, signinText, loginRedirect,
     * loginPage, identityName, challenge, recovery, pageError, readyState,
     * title, url, bodyLen, bodySample.
     */
    public static function youtubeEvidenceJs(): string
    {
        $av = json_encode(self::AVATAR, JSON_UNESCAPED_UNICODE);
        $cta = json_encode(self::SIGNIN_CTA, JSON_UNESCAPED_UNICODE);
        $signinTxt = json_encode(self::SIGNIN_TEXT, JSON_UNESCAPED_UNICODE);
        return "(()=>{try{"
            . "const AV=$av,CTA=$cta,TXT=$signinTxt;"
            . "const q=s=>{try{return !!document.querySelector(s)}catch(e){return false}};"
            . "const qn=s=>{try{return document.querySelectorAll(s).length}catch(e){return 0}};"
            . "let av=false,avN=0;for(const s of AV){const n=qn(s);if(n>0){av=true;avN+=n}}"
            . "let cta=false;for(const s of CTA){if(q(s)){cta=true;break}}"
            . "const bt=(document.body?document.body.innerText.slice(0,4000):'');"
            . "const bl=bt.toLowerCase();"
            . "let txt=false;for(const t of TXT){if(bl.includes(t)){txt=true;break}}"
            . "const u=location.href,t=document.title||'';"
            . "const loginRe=/\\/signin|\\/login|ServiceLogin|accounts\\.google\\.com/i;"
            . "const chRe=/challenge|verify|confirm.*identity|xac.*minh/i;"
            . "const rcRe=/recover|khoi.*phuc/i;"
            . "const errRe=/ERR_PROXY_CONNECTION_FAILED|ERR_TIMED_OUT|ERR_INTERNET_DISCONNECTED|ERR_NAME_NOT_RESOLVED|offline|dns|no internet|không có.*mạng|proxy.*lỗi/i;"
            . "let nm=null;try{const el=document.querySelector('button#avatar-btn img, #avatar-btn img');"
            . "if(el){nm=(el.getAttribute('alt')||'').slice(0,80)||null}}catch(e){}"
            . "let menu=false;try{menu=!!document.querySelector('ytd-popup-container, tp-yt-iron-dropdown [role=\"menu\"], ytd-menu-popup-renderer')}catch(e){}"
            . "return {av:av,avN:avN,signinCta:cta,signinText:txt,"
            . "loginRedirect:loginRe.test(u+' '+t),loginPage:loginRe.test(u)&&/sign|đăng nhập/i.test(bl.slice(0,500)),"
            . "identityName:nm,accountMenu:menu,"
            . "ch:chRe.test(u+' '+t),rc:rcRe.test(u),"
            . "pageError:errRe.test(u+' '+t+' '+bl.slice(0,500)),"
            . "rs:document.readyState,t:t.slice(0,120),u:u.slice(0,220),"
            . "bodyLen:bl.length,body:bt.slice(0,1500)}"
            . "}catch(e){return null}})()";
    }

    /**
     * JS thu evidence GOOGLE (myaccount page): profile identity vs signin form.
     */
    public static function googleEvidenceJs(): string
    {
        return "(()=>{try{"
            . "const u=location.href,t=document.title||'';"
            . "const bt=(document.body?document.body.innerText.slice(0,4000):'');"
            . "const bl=bt.toLowerCase();"
            . "const q=s=>{try{return !!document.querySelector(s)}catch(e){return false}};"
            . "const loginRe=/ServiceLogin|\\/signin|\\/login|accounts\\.google\\.com\\/signin/i;"
            . "const chRe=/challenge|verify|xac.*minh|confirm.*identity/i;"
            . "const rcRe=/recover|khoi.*phuc/i;"
            . "const errRe=/ERR_PROXY_CONNECTION_FAILED|ERR_TIMED_OUT|ERR_INTERNET_DISCONNECTED|ERR_NAME_NOT_RESOLVED|offline|no internet/i;"
            . "const av=q('a[aria-label*=\"@\" i], a[href*=\"myaccount.google.com\" i] img, img[alt*=\"profile\" i], [data-email], [aria-label*=\"Google Account\" i]');"
            . "const form=q('input[type=\"email\"], input[type=\"password\"], form[action*=\"ServiceLogin\" i]');"
            . "const cta=q('a[href*=\"ServiceLogin\" i]');"
            . "let email=null;try{const m=bt.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i);email=m?m[0].slice(0,80):null}catch(e){}"
            . "return {av:av,signinForm:form,signinCta:cta,email:email,"
            . "loginRedirect:loginRe.test(u+' '+t),loginPage:form&&loginRe.test(u),"
            . "ch:chRe.test(u+' '+t),rc:rcRe.test(u),"
            . "pageError:errRe.test(u+' '+t+' '+bl.slice(0,500)),"
            . "rs:document.readyState,t:t.slice(0,120),u:u.slice(0,220),bodyLen:bl.length}"
            . "}catch(e){return null}})()";
    }

    /**
     * JS thu evidence CHANNEL (@me redirect + markers tao-kenh).
     */
    public static function channelEvidenceJs(): string
    {
        $mk = json_encode(self::CREATE_CHANNEL_TEXT, JSON_UNESCAPED_UNICODE);
        return "(()=>{try{const MK=$mk;"
            . "const u=location.href,t=document.title||'';"
            . "const body=(document.body?document.body.innerText.slice(0,3000):'');"
            . "const bl=body.toLowerCase();"
            . "let create=false;for(const m of MK){if(bl.includes(m)){create=true;break}}"
            . "const ch=/challenge|verify/i.test(u+' '+t);"
            . "const rc=/recover/i.test(u);"
            . "const restricted=/restricted|bị hạn chế|account.*suspend|kênh.*vi phạm|suspended/i.test(u+' '+body.slice(0,1000));"
            . "return {u:u.slice(0,220),t:t.slice(0,120),body:body,create:create,ch:ch,rc:rc,restricted:restricted}"
            . "}catch(e){return null}})()";
    }
}
