let prefix = location.href
const q = prefix.indexOf('?')
if (q > 0) {
    prefix = prefix.substring(0, q + 1)
}
const PREFIX = prefix
const TARGET_ORIGIN = new URL(location.search.substring(1)).origin

console.log('PREFIX=' + PREFIX + ', TARGET_ORIGIN=' + TARGET_ORIGIN)

const {
    getOwnPropertyDescriptor,
    defineProperty,
    apply,
    construct,
} = Reflect

function proxyUrl(url) {
    if (url.startsWith(PREFIX)) {
        return url;
    }
    if (!isHttpProto(url)) {
        if (url.indexOf("://") > 0 || url.startsWith("blob:") || url.startsWith("data")) {
            return url;
        } else {
            url = TARGET_ORIGIN + url;
        }
    }
    if (url.startsWith(location.origin)) {
        url = TARGET_ORIGIN + url.substr(location.origin.length);
    }
    return PREFIX + url;
}

/**
 * @param {string} url 
 */
function isHttpProto(url) {
    return /^https?:/.test(url) || url.startsWith("//")
}

/**
 * hook function
 * 
 * @param {object} obj 
 * @param {string} key 
 * @param {(oldFn: Function) => Function} factory
 */
function func(obj, key, factory) {
    /** @type {Function} */
    const oldFn = obj[key]
    if (!oldFn) {
        return false
    }

    const newFn = factory(oldFn)

    Object.keys(oldFn).forEach(k => {
        newFn[k] = oldFn[k]
    })

    const proto = oldFn.prototype
    if (proto) {
        newFn.prototype = proto
    }

    obj[key] = newFn
    return true
}

/**
 * hook property
 * 
 * @param {object} obj 
 * @param {string} key 
 * @param {(oldFn: () => any) => Function=} g 
 * @param {(oldFn: () => void) => Function=} s 
 */
function prop(obj, key, g, s) {
    const desc = getOwnPropertyDescriptor(obj, key)
    if (!desc) {
        return false
    }
    if (g) {
        func(desc, 'get', g)
    }
    if (s) {
        func(desc, 'set', s)
    }
    defineProperty(obj, key, desc)
    return true
}

/**
 * Hook 页面和 Worker 相同的 API
 * 
 * @param {Window} global WindowOrWorkerGlobalScope
 */
function initHook(global) {
    // lockNative(win)

    // hook Storage API
    //createStorage(global, origin)

    // hook Location API
    //const fakeLoc = createFakeLoc(global)

    // hook Performance API
    const perfProto = global['PerformanceEntry'].prototype
    prop(perfProto, 'name',
        getter => function () {
            const val = getter.call(this)
            if (/^https?:/.test(val)) {
                return proxyUrl(val)
            }
            return val
        }
    )

    // hook Image src
    const imgProto = global['Image'].prototype
    prop(imgProto, 'src', null,
        setter => function (val) {
            setter.call(this, proxyUrl(val))
            console.log(`proxy img.src=${val} to ` + this.src)
        }
    )

    // hook AJAX API
    const xhrProto = global['XMLHttpRequest'].prototype
    func(xhrProto, 'open', oldFn => function (_0, url) {
        if (url) {
            arguments[1] = proxyUrl(url)
        }
        return apply(oldFn, this, arguments)
    })

    prop(xhrProto, 'responseURL',
        getter => function (oldFn) {
            const val = getter.call(this)
            return decUrlStrRel(val, this)
        }
    )


    func(global, 'fetch', oldFn => function (v) {
        if (v) {
            if (v.url) {
                // v is Request
                const newUrlStr = proxyUrl(v.url)
                arguments[0] = new Request(newUrlStr, v)
            } else {
                // v is string
                // TODO: 字符串不传引用，无法获取创建时的 constructor
                arguments[0] = proxyUrl(v)
            }
        }
        return apply(oldFn, this, arguments)
    })

    /*
    func(global, 'WebSocket', oldFn => function (url) {
        const urlObj = newUrl(url)
        if (urlObj) {
            const { ori } = env.get(this)
            if (ori) {
                const args = {
                    'origin': ori.origin,
                }
                arguments[0] = route.genWsUrl(urlObj, args)
            }
        }
        return construct(oldFn, arguments)
    })
    */

    func(global, 'importScripts', oldFn => function (...args) {
        const urls = args.map(proxyUrl)
        console.log('[jsproxy] importScripts:', urls)
        return apply(oldFn, this, urls)
    })

    // hook beacon
    const beaconProto = global['Navigator'].prototype
    func(beaconProto, 'sendBeacon', oldFn => function (url) {
        if (url) {
            arguments[0] = proxyUrl(url)
        }
        return apply(oldFn, this, arguments)
    })

    function proxyNode(node) {
        if (!node) {
            return
        }
        if (node.src && typeof (node.src) === 'string') {
            let url = proxyUrl(node.src)
            if (node.src != url) {
                node.src = url
                console.log("node src to " + node.src)
                // we need to load script again
                if (node.tagName.toUpperCase() == 'SCRIPT') {
                    var newScript = document.createElement("script")
                    newScript.src = node.src
                    newScript.type = 'text/javascript'
                    node.parentNode.appendChild(newScript)
                }
            }
        }
        if (node.href && typeof (node.href) === 'string') {
            let url = proxyUrl(node.href)
            if (url != node.href) {
                node.href = url
                console.log("node href to " + node.href)
            }
        }
        if (node.style) {
            const regex = /url\(["']?(.*?)["']?\)/i
            for (var k of node.style) {
                const style = node.style[k].trim()
                if (style.startsWith('url(')) {
                    const oldUrl = style.replace(regex, '$1')
                    const url = proxyUrl(oldUrl)
                    if (oldUrl != url) {
                        node.style[k] = 'url("' + url + '")'
                        console.log('url style to :' + node.style[k])
                    }
                }
            }
        }
        if (node.childNodes) {
            node.childNodes.forEach(e => proxyNode(e));
        }
    }

    // dom change observe
    var MutationObserver = window.MutationObserver || window.WebKitMutationObserver || window.MozMutationObserver
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.type == 'childList') {
                mutation.addedNodes.forEach(node => proxyNode(node))
            } else {
                proxyNode(mutation.target)
            }
        })
    })

    observer.observe(document.documentElement, {
        childList: true,
        attributes: true,
        attributeFilter: ['src', 'href', 'style'],
        subtree: true
    });
}

// Initialize proxy
if (navigator.serviceWorker) {
    navigator.serviceWorker.register(`sw.js?p=${btoa(PREFIX)}&t=${btoa(TARGET_ORIGIN)}`).then(registration => {
        console.log('[SW] proxy server install, scope: ', registration.scope);
        if (registration.installing) {
            const sw = registration.installing || registration.waiting;
            sw.onstatechange = function () {
                if (sw.state === 'installed') {
                    // SW installed.  Refresh page so SW can respond with SW-enabled page.
                    window.location.reload();
                }
            };
        }
    }).catch(err => {
        console.error('error registering SW:', err)
        initHook(self);
    });
} else {
    console.warn('[SW] is not supported! Back to hook method.');
    console.log('use hook method instead')
    initHook(self);
}

// Hide youtube title
if (location.search.indexOf("notitle") >= 0) {
    var css = 'div.ytp-show-cards-title, div.ytp-gradient-top { display: none; }';
    var head = document.head || document.getElementsByTagName('head')[0];
    var style = document.createElement('style');
    head.appendChild(style);
    style.type = 'text/css';
    if (style.styleSheet) {
        // This is required for IE8 and below.
        style.styleSheet.cssText = css;
    } else {
        style.appendChild(document.createTextNode(css));
    }
}
