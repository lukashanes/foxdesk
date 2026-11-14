const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/page-transitions.js','utf8');
function fixture() {
    const listeners={}, requests=[], redirects=[];
    const node={dataset:{workFilterMode:'last3',workActiveKey:'mine'},setAttribute(){},removeAttribute(){}};
    const location={href:'https://app.test/index.php?page=work',origin:'https://app.test',pathname:'/index.php',assign:url=>redirects.push(url),reload(){throw new Error('Unexpected reload');}};
    const document={body:{dataset:{}},activeElement:null,querySelector(selector){
        if(selector==='.work-period-switch .is-active') return {href:'https://app.test/index.php?page=work&period=this_month'};
        if(selector==='.work-custom-period') return {querySelector:()=>({value:'mine'})};
        return node;
    },addEventListener:(name,fn)=>listeners[name]=fn};
    const history={state:null,replaceState(state,_,url){this.state=state;location.href=url;},pushState(){throw new Error('Failed response must not enter history');}};
    const window={crypto:{randomUUID:()=> 'test-document'},scrollY:42,addEventListener(){}};
    const fetch=(url,options)=>new Promise(resolve=>requests.push({url,options,resolve}));
    vm.runInNewContext(source,{document,window,location,history,URL,URLSearchParams,FormData,AbortController,setTimeout,clearTimeout,fetch});
    function click(key,value){
        const link={href:`https://app.test/index.php?page=work&${key}=${value}`,target:'',dataset:{workQueueKey:value},hasAttribute:name=>key==='queue'&&name==='data-work-queue-key',closest:selector=>selector==='.work-period-switch'?(key==='period'?node:null):selector.includes('data-work-')?node:null};
        listeners.click({button:0,target:{closest:()=>link},preventDefault(){}});
    }
    return {requests,redirects,click};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const failure=()=>({ok:false,redirected:false,headers:{get:()=> 'text/html'},text:async()=> 'Temporary error'});
(async()=>{
    const f=fixture();
    f.click('period','this_week');f.click('period','last_month');f.click('period','this_month');
    assert.equal(f.requests.length,1,'Only one preference-saving request may run at a time');
    f.requests[0].resolve(failure());await tick();
    assert.equal(f.redirects.length,0,'An obsolete failed response must not navigate away');
    assert.equal(f.requests.length,2,'Returning to the displayed period must still restore the final server preference');
    assert.equal(new URL(f.requests[1].url).searchParams.get('period'),'this_month');
    f.requests[1].resolve(failure());await tick();
    assert.equal(f.redirects.length,1);
    assert.equal(new URL(f.redirects[0]).searchParams.get('period'),'this_month','Fallback must use the latest choice');
    const mixed=fixture();mixed.click('period','this_week');mixed.click('queue','unassigned');
    mixed.requests[0].resolve(failure());await tick();
    const target=new URL(mixed.requests[1].url);
    assert.equal(target.searchParams.get('period'),'this_week','A queued queue change must retain the pending period');
    assert.equal(target.searchParams.get('queue'),'unassigned');
    mixed.requests[1].resolve(failure());await tick();
    console.log('PASS: serialized requests, obsolete failures, rapid revert, mixed filters and native fallback');
})().catch(error=>{console.error(error);process.exitCode=1;});
