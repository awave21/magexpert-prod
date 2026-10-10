// Загружает IFrame API плеера Кинескопа один раз на всё приложение и отдаёт фабрику плееров.
const SCRIPT_URL = 'https://player.kinescope.io/latest/iframe.player.js';

let factoryPromise = null;

export default function loadKinescope() {
    if (factoryPromise) {
        return factoryPromise;
    }

    factoryPromise = new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Kinescope API timeout')), 15000);
        const previous = window.onKinescopeIframeAPIReady;

        window.onKinescopeIframeAPIReady = (factory) => {
            clearTimeout(timer);
            resolve(factory);
            if (typeof previous === 'function') {
                previous(factory);
            }
        };

        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.onerror = () => {
            clearTimeout(timer);
            reject(new Error('Kinescope API failed to load'));
        };
        document.head.appendChild(script);
    }).catch((error) => {
        factoryPromise = null;
        throw error;
    });

    return factoryPromise;
}
