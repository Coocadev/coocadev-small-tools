/**
 * Exercise the real VS Code PHP Debug adapter through DAP, backed by Xdebug.
 * Stops in every installed plugin entry point, inspects a safe expression,
 * steps once, then resumes. It never reads database credentials or cookies.
 * Copyright 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const {once, EventEmitter} = require('node:events');
const root = path.resolve(__dirname, '..');
const catalog = JSON.parse(fs.readFileSync(path.join(root, 'catalog.json'), 'utf8'));
const extensionRoot = path.join(root, 'qa/runtime/vscode-extensions');
const extension = fs.readdirSync(extensionRoot).find(name => /^xdebug\.php-debug-/.test(name));
if (!extension) throw new Error('Install xdebug.php-debug into the project-specific extensions directory.');
const adapter = path.join(extensionRoot, extension, 'out/phpDebug.js');
const processAdapter = spawn(process.execPath, [adapter], {cwd: root, stdio: ['pipe', 'pipe', 'pipe']});
let buffer = Buffer.alloc(0), sequence = 0, stoppedChain = Promise.resolve();
let finishing = false, adapterExited = false, failure, rejectFailure, resolveAdapterExit;
const waiting = new Map(), events = new EventEmitter(), hits = new Set(), steps = new Set();
const expectedSteps = new Map(), outputLines = new Map();
const report = {version: catalog.version, adapter: extension, xdebug: null, started: new Date().toISOString(), breakpoints: [], stops: [], safeEvaluations: [], stderr: []};
const failed = new Promise((resolve, reject) => { rejectFailure = reject; });
// A launch failure may precede the next await. Keep the shared rejection handled.
failed.catch(() => {});
const adapterExit = new Promise(resolve => { resolveAdapterExit = resolve; });
function failSession(error) {
    if (!failure) {
        failure = error instanceof Error ? error : new Error(String(error));
        rejectFailure(failure);
    }
}
function guarded(promise) {
    return Promise.race([promise, failed]);
}
function rejectPending(error) {
    for (const pending of waiting.values()) { clearTimeout(pending.timeout); pending.reject(error); }
    waiting.clear();
}
function request(command, args = {}, timeoutMs = 45000) {
    if (adapterExited || !processAdapter.stdin.writable) return Promise.reject(new Error('DAP adapter is unavailable: ' + command));
    const id = ++sequence;
    const body = JSON.stringify({seq: id, type: 'request', command, arguments: args});
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            waiting.delete(id);
            const error = new Error('DAP request timed out: ' + command);
            reject(error);
            if (!finishing) failSession(error);
        }, timeoutMs);
        waiting.set(id, {resolve, reject, timeout});
        processAdapter.stdin.write('Content-Length: ' + Buffer.byteLength(body) + '\r\n\r\n' + body, error => {
            if (!error) return;
            const pending = waiting.get(id);
            if (pending) { clearTimeout(pending.timeout); waiting.delete(id); pending.reject(error); }
            if (!finishing) failSession(error);
        });
    });
}
processAdapter.stdout.on('data', data => {
    try {
        buffer = Buffer.concat([buffer, data]);
        if (buffer.length > 8 * 1024 * 1024) throw new Error('DAP frame exceeded the local harness limit.');
        while (true) {
            const boundary = buffer.indexOf('\r\n\r\n');
            if (boundary < 0) return;
            const match = /Content-Length:\s*(\d+)/i.exec(buffer.subarray(0, boundary).toString());
            if (!match) throw new Error('Invalid DAP header.');
            const size = Number(match[1]);
            if (!Number.isSafeInteger(size) || size < 1 || size > 8 * 1024 * 1024) throw new Error('Invalid DAP frame size.');
            if (buffer.length < boundary + 4 + size) return;
            const message = JSON.parse(buffer.subarray(boundary + 4, boundary + 4 + size).toString());
            buffer = buffer.subarray(boundary + 4 + size);
            if (message.type === 'response') {
                const pending = waiting.get(message.request_seq);
                if (pending) {clearTimeout(pending.timeout); waiting.delete(message.request_seq); message.success ? pending.resolve(message.body || {}) : pending.reject(new Error(message.message || message.command));}
            } else if (message.type === 'event') {
                events.emit(message.event, message.body || {});
            }
        }
    } catch (error) { failSession(error); }
});
processAdapter.stderr.on('data', data => report.stderr.push(data.toString()));
processAdapter.on('error', error => {
    adapterExited = true;
    report.adapterError = String(error);
    rejectPending(error);
    resolveAdapterExit();
    if (!finishing) failSession(error);
});
processAdapter.on('exit', (code, signal) => {
    adapterExited = true;
    report.adapterExit = {code, signal};
    resolveAdapterExit();
    const error = new Error('DAP adapter exited' + (finishing ? ' during cleanup.' : ' unexpectedly: ' + code + '/' + signal));
    rejectPending(error);
    if (!finishing) failSession(error);
});
processAdapter.stdin.on('error', error => {
    rejectPending(error);
    if (!finishing) failSession(error);
});
function inspectOutputLine(line) {
    if (/PHP (?:Warning|Notice|Fatal error|Parse error)/.test(line)) report.runtimeError = line.slice(0, 500);
    if (line.trim() === 'DEBUG_BOOTSTRAP_OK ' + catalog.products.length) report.probeCompleted = true;
    if (!line.trim().startsWith('{"wp":')) return;
    const probe = JSON.parse(line);
    if (!['wp', 'php', 'xdebug'].every(key => typeof probe[key] === 'string' && /^\d+\.\d+(?:\.\d+)?(?:[-\w.]*)$/.test(probe[key]))) throw new Error('Missing actual PHP, WordPress or Xdebug version in the probe.');
    if (!Array.isArray(probe.plugins) || probe.plugins.length !== catalog.products.length) throw new Error('The debug probe returned the wrong plugin count.');
    const actual = new Set();
    for (const plugin of probe.plugins) {
        if (!catalog.products.some(product => product.slug === plugin.plugin) || plugin.version !== catalog.version || plugin.active !== true || actual.has(plugin.plugin)) throw new Error('The debug probe did not verify the exact active catalog release.');
        actual.add(plugin.plugin);
    }
    // Whitelist harmless probe metadata; never persist scopes, environment or credentials.
    report.probe = {wp: probe.wp, php: probe.php, xdebug: probe.xdebug, plugins: probe.plugins.map(plugin => ({plugin: plugin.plugin, version: plugin.version, active: plugin.active}))};
    report.xdebug = probe.xdebug;
}
events.on('output', output => {
    try {
        const category = output.category || 'console';
        const text = (outputLines.get(category) || '') + (output.output || '');
        if (text.length > 65536) throw new Error('Debug output line exceeded the local harness limit.');
        const lines = text.split(/\r?\n/);
        outputLines.set(category, lines.pop());
        for (const line of lines) inspectOutputLine(line);
    } catch (error) { failSession(error); }
});
events.on('exited', body => {
    report.debuggeeExitCode = body.exitCode;
    if (body.exitCode !== 0) failSession(new Error('PHP debuggee exited with code ' + body.exitCode));
});
events.on('stopped', stop => {
    stoppedChain = stoppedChain.then(async () => {
        const stack = await guarded(request('stackTrace', {threadId: stop.threadId, startFrame: 0, levels: 1}));
        const frame = stack.stackFrames && stack.stackFrames[0];
        if (!frame) throw new Error('The stopped debugger returned no stack frame.');
        const source = frame.source && frame.source.path ? frame.source.path.replace(/\\/g, '/') : '';
        const product = catalog.products.find(item => source.endsWith('/' + item.slug + '/' + item.slug + '.php'));
        report.stops.push({reason: stop.reason, source, line: frame.line, plugin: product ? product.slug : null});
        const expected = expectedSteps.get(stop.threadId);
        if (expected) {
            if (stop.reason !== 'step' || !product || product.slug !== expected) throw new Error('Expected an actual single-step stop in ' + expected);
            steps.add(product.slug);
            expectedSteps.delete(stop.threadId);
            await guarded(request('continue', {threadId: stop.threadId}));
        } else if (product && !hits.has(product.slug) && stop.reason === 'breakpoint') {
            hits.add(product.slug);
            const safe = await guarded(request('evaluate', {expression: 'defined("ABSPATH") && PHP_VERSION_ID >= 80100', frameId: frame.id, context: 'watch'}));
            report.safeEvaluations.push({plugin: product.slug, result: safe.result});
            if (safe.result !== 'true') throw new Error('The safe WordPress/PHP expression was not true in ' + product.slug);
            expectedSteps.set(stop.threadId, product.slug);
            await guarded(request('next', {threadId: stop.threadId}));
        } else {
            throw new Error('Unexpected debugger stop: ' + stop.reason + ' at ' + source + ':' + frame.line);
        }
    }).catch(failSession);
});
(async () => {
    const deadline = setTimeout(() => failSession(new Error('Debug session exceeded 180 seconds.')), 180000);
    try {
        await guarded(request('initialize', {adapterID: 'php', clientID: 'coocadev-dap-verifier', linesStartAt1: true, columnsStartAt1: true, pathFormat: 'path', supportsVariableType: true}));
        const initialized = once(events, 'initialized');
        const launching = request('launch', {
            name: 'Small Tools real PHP debug', type: 'php', request: 'launch',
            runtimeExecutable: path.join(root, 'qa/runtime/php81/php.exe'),
            runtimeArgs: ['-d', 'zend_extension=' + path.join(root, 'qa/runtime/php81/ext/php_xdebug-3.4.5.dll'), '-d', 'xdebug.mode=debug', '-d', 'xdebug.start_with_request=yes', '-d', 'xdebug.client_host=127.0.0.1'],
            program: path.join(root, 'qa/downloads/wp-cli.phar'),
            args: ['--path=' + path.join(root, 'qa/sites/combined'), 'eval-file', path.join(root, 'qa/debug-probe.php')],
            cwd: root, hostname: '127.0.0.1', port: 0, stopOnEntry: false,
            env: {XDEBUG_CONFIG: 'client_port=${port}'},
            xdebugSettings: {max_children: 8, max_depth: 2, max_data: 128}
        });
        // This adapter responds to launch before initialized, including launch errors.
        // Awaiting initialized first would hang on an error with no initialized event.
        await guarded(launching);
        await guarded(initialized);
        for (const product of catalog.products) {
            const source = path.join(root, 'qa/sites/combined/wp-content/plugins', product.slug, product.slug + '.php');
            const lines = fs.readFileSync(source, 'utf8').split(/\r?\n/);
            const line = lines.findIndex(value => /^\s*defined\(\s*['"]ABSPATH['"]/.test(value)) + 1;
            if (!line) throw new Error('No executable entry guard in ' + product.slug);
            const response = await guarded(request('setBreakpoints', {source: {path: source}, breakpoints: [{line}]}));
            report.breakpoints.push({plugin: product.slug, requestedLine: line, response: response.breakpoints});
            if (!response.breakpoints || response.breakpoints.length !== 1 || !response.breakpoints[0].verified) throw new Error('The adapter rejected the breakpoint in ' + product.slug);
        }
        await guarded(request('setExceptionBreakpoints', {filters: []}));
        const terminated = once(events, 'terminated');
        await guarded(request('configurationDone'));
        await guarded(terminated);
        await guarded(stoppedChain);
        for (const line of outputLines.values()) if (line) inspectOutputLine(line);
        if (failure) throw failure;
        report.hitPlugins = [...hits]; report.steppedPlugins = [...steps];
        report.pass = !report.runtimeError && report.probeCompleted === true && !!report.probe && report.debuggeeExitCode === 0 && hits.size === catalog.products.length && steps.size === catalog.products.length && report.safeEvaluations.length === catalog.products.length && expectedSteps.size === 0;
        if (!report.pass) throw new Error('Expected every plugin breakpoint, true evaluation, actual single step and a successful versioned bootstrap.');
    } catch (error) {
        report.pass = false; report.error = String(error); process.exitCode = 1;
    } finally {
        clearTimeout(deadline);
        finishing = true;
        report.hitPlugins = [...hits]; report.steppedPlugins = [...steps];
        report.cleanup = {disconnectAcknowledged: false, adapterExited: false};
        if (!adapterExited) {
            try {
                // The adapter owns and terminates its PHP child; killing Node alone can
                // orphan a stopped PHP process on Windows. Bound this cleanup to 5s.
                await request('disconnect', {terminateDebuggee: true}, 5000);
                report.cleanup.disconnectAcknowledged = true;
            } catch (error) {
                report.cleanup.error = String(error);
                report.pass = false;
                report.error = report.error || 'DAP disconnect failed; debuggee cleanup was not confirmed.';
                process.exitCode = 1;
            }
            if (!adapterExited) processAdapter.kill();
        }
        let exitTimeout;
        await Promise.race([adapterExit, new Promise(resolve => { exitTimeout = setTimeout(resolve, 2000); })]);
        clearTimeout(exitTimeout);
        report.cleanup.adapterExited = adapterExited;
        if (!adapterExited) {
            report.pass = false;
            report.error = report.error || 'The owned DAP adapter did not exit within the cleanup deadline.';
            process.exitCode = 1;
        }
        rejectPending(new Error('Debug harness completed.'));
        report.finished = new Date().toISOString();
        const target = path.join(root, 'reports', catalog.version); fs.mkdirSync(target, {recursive:true});
        fs.writeFileSync(path.join(target, 'vscode-xdebug.json'), JSON.stringify(report, null, 2));
    }
    if (report.pass) console.log('PASS VS Code PHP Debug/Xdebug: ' + hits.size + ' plugin entry points, ' + steps.size + ' actual single steps, safe expressions true; WP ' + report.probe.wp + ', PHP ' + report.probe.php + ', Xdebug ' + report.probe.xdebug + '; bounded disconnect acknowledged.');
    else console.error(report.error);
})().catch(error => {console.error(error); processAdapter.kill(); process.exitCode=1;});
