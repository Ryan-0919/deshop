const path = require('path');

const hbuilderxPlugins = process.env.UNI_HBUILDERX_PLUGINS;

if (!hbuilderxPlugins) {
    throw new Error('UNI_HBUILDERX_PLUGINS is required to compile this project.');
}

const uniappCliRoot = path.join(hbuilderxPlugins, 'uniapp-cli');
const config = require(path.join(uniappCliRoot, 'babel.config.js'));
const uniappNodeModules = path.join(uniappCliRoot, 'node_modules');

function resolveFromUniappCli(name) {
    const aliases = {
        import: 'babel-plugin-import',
        '@vue/app': '@vue/babel-preset-app'
    };

    return require.resolve(aliases[name] || name, {
        paths: [uniappNodeModules]
    });
}

config.presets = config.presets.map((preset) => {
    if (Array.isArray(preset) && typeof preset[0] === 'string') {
        return [resolveFromUniappCli(preset[0]), ...preset.slice(1)];
    }
    return typeof preset === 'string' ? resolveFromUniappCli(preset) : preset;
});

config.plugins = config.plugins.map((plugin) => {
    if (Array.isArray(plugin) && typeof plugin[0] === 'string') {
        return [resolveFromUniappCli(plugin[0]), ...plugin.slice(1)];
    }
    return typeof plugin === 'string' ? resolveFromUniappCli(plugin) : plugin;
});

config.plugins.push(
    resolveFromUniappCli('@babel/plugin-proposal-optional-chaining')
);

module.exports = config;
