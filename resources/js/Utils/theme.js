export function hexToRgb(hex) {
    // Expand shorthand form (e.g. "03F") to full form (e.g. "0033FF")
    const shorthandRegex = /^#?([a-f\d])([a-f\d])([a-f\d])$/i;
    hex = hex.replace(shorthandRegex, (m, r, g, b) => r + r + g + g + b + b);

    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? {
        r: parseInt(result[1], 16),
        g: parseInt(result[2], 16),
        b: parseInt(result[3], 16)
    } : { r: 0, g: 0, b: 0 };
}

export function mix(color1Hex, color2Hex, weight) {
    const c1 = hexToRgb(color1Hex);
    const c2 = hexToRgb(color2Hex);

    const r = Math.round(c1.r * weight + c2.r * (1 - weight));
    const g = Math.round(c1.g * weight + c2.g * (1 - weight));
    const b = Math.round(c1.b * weight + c2.b * (1 - weight));

    return { r, g, b };
}

export function applyTheme(primaryHex, secondaryHex) {
    const root = document.documentElement;

    const generateShades = (name, hex) => {
        const shades = {
            50: { w: '#ffffff', p: 0.95 },
            100: { w: '#ffffff', p: 0.9 },
            200: { w: '#ffffff', p: 0.8 },
            300: { w: '#ffffff', p: 0.6 },
            400: { w: '#ffffff', p: 0.3 },
            500: { base: true },
            600: { b: '#000000', p: 0.1 },
            700: { b: '#000000', p: 0.3 },
            800: { b: '#000000', p: 0.55 },
            900: { b: '#000000', p: 0.8 },
            950: { b: '#000000', p: 0.92 },
        };

        Object.entries(shades).forEach(([key, value]) => {
            let rgb;
            if (value.base) {
                rgb = hexToRgb(hex);
            } else if (value.w) {
                rgb = mix(value.w, hex, value.p);
            } else {
                rgb = mix(value.b, hex, value.p);
            }
            root.style.setProperty(`--color-${name}-${key}`, `${rgb.r} ${rgb.g} ${rgb.b}`);
        });
    };

    generateShades('primary', primaryHex);
    generateShades('secondary', secondaryHex);
}
