// ===== SERVEUR D'ENVOI D'EMAILS AVEC AUTHENTIFICATION SMTP =====
// Utilise : Express, Nodemailer, Socket.IO

const express = require('express');
const nodemailer = require('nodemailer');
const bodyParser = require('body-parser');
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');
const { Server } = require("socket.io");
const session = require('express-session');

const app = express();
const server = http.createServer(app);
const io = new Server(server);
const port = 3001;

// Configuration de la session (stockage en mémoire)
app.use(session({
    secret: 'neonmail-super-secret-key-2024-maitre-yoda',
    resave: false,
    saveUninitialized: false,
    cookie: { 
        secure: false,
        maxAge: 24 * 60 * 60 * 1000 // 24 heures
    }
}));

// Global control variables
let isPaused = false;
let isStopped = false;

// Middleware
app.use(bodyParser.urlencoded({ extended: true }));
app.use(bodyParser.json({ limit: '50mb' }));
app.use(express.static(path.join(__dirname, 'public')));

// Dossier pour les pièces jointes
const pjDir = path.join(__dirname, 'PJ');
const failedEmailsPath = path.join(__dirname, 'failed_emails.json');

if (!fs.existsSync(pjDir)) {
    fs.mkdirSync(pjDir, { recursive: true });
}

// Gestion des emails échoués
function loadFailedEmails() {
    try {
        if (fs.existsSync(failedEmailsPath)) {
            const data = fs.readFileSync(failedEmailsPath, 'utf8');
            return JSON.parse(data);
        }
    } catch (e) {
        console.error('Error loading failed emails:', e);
    }
    return {};
}

function saveFailedEmails(data) {
    try {
        fs.writeFileSync(failedEmailsPath, JSON.stringify(data, null, 2));
    } catch (e) {
        console.error('Error saving failed emails:', e);
    }
}

// ---- VÉRIFICATION DU MOT DE PASSE PAR URL (OPTIONNELLE) ----
const PASSWORD_URL = 'https://pastebin.com/raw/VOTRE_CODE_SECRET';
const PASSWORD_ENABLED = false; // Mettre à true pour activer

async function verifyPassword(password) {
    if (!PASSWORD_ENABLED) return true;
    if (!password) return false;
    try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);
        const response = await fetch(PASSWORD_URL, { signal: controller.signal });
        clearTimeout(timeout);
        if (!response.ok) return false;
        const expected = (await response.text()).trim();
        return password.trim() === expected;
    } catch (e) {
        console.error('Password verification error:', e.message);
        return false;
    }
}

// Route de vérification du mot de passe (authentification unique)
app.post('/verify-password', async (req, res) => {
    const { password } = req.body;
    
    if (!password) {
        return res.status(400).json({ success: false, error: 'Mot de passe requis' });
    }
    
    const isValid = await verifyPassword(password);
    
    if (isValid) {
        req.session.passwordValidated = true;
        req.session.validationTime = Date.now();
        io.emit('log', { type: 'success', message: '🔐 Authentification réussie ! Session valide pour 24h.' });
        res.json({ success: true, message: 'Password validated' });
    } else {
        io.emit('log', { type: 'error', message: '❌ Mot de passe incorrect. Accès refusé.' });
        res.status(401).json({ success: false, error: 'Invalid password' });
    }
});

// Route pour vérifier l'état de l'authentification
app.get('/check-auth', (req, res) => {
    const isValid = req.session.passwordValidated === true;
    const validationTime = req.session.validationTime;
    
    // Vérifier si la session n'est pas expirée (optionnel)
    if (isValid && validationTime && (Date.now() - validationTime) > 24 * 60 * 60 * 1000) {
        req.session.passwordValidated = false;
        res.json({ authenticated: false, expired: true });
    } else {
        res.json({ 
            authenticated: isValid,
            validationTime: validationTime
        });
    }
});

// Route pour déconnecter (optionnel)
app.post('/logout', (req, res) => {
    req.session.destroy();
    io.emit('log', { type: 'system', message: '🔓 Utilisateur déconnecté' });
    res.json({ success: true, message: 'Déconnecté' });
});

// Control routes
app.post('/pause', (req, res) => {
    isPaused = !isPaused;
    io.emit('log', { type: 'system', message: isPaused ? '⏸️ Envoi PAUSE par l\'utilisateur.' : '▶️ Envoi REPRISE.' });
    io.emit('status_update', { paused: isPaused, stopped: isStopped });
    res.json({ success: true, paused: isPaused });
});

app.post('/stop', (req, res) => {
    isStopped = true;
    io.emit('log', { type: 'error', message: '🛑 SIGNAL D\'ABANDON REÇU. Arrêt de l\'envoi...' });
    io.emit('status_update', { paused: isPaused, stopped: isStopped });
    res.json({ success: true });
});

// Stats des emails échoués
app.get('/api/failed-count', (req, res) => {
    const failed = loadFailedEmails();
    const count = Object.keys(failed).length;
    res.json({
        count,
        batches: Object.keys(failed).map(id => ({
            id,
            recipientsCount: failed[id].recipients ? failed[id].recipients.length : 0,
            attempts: failed[id].attempts || 0,
            lastError: failed[id].lastError || 'Inconnu'
        }))
    });
});

// Route pour lister les fichiers PJ
app.get('/api/pj-files', (req, res) => {
    fs.readdir(pjDir, (err, files) => {
        if (err) return res.json({ files: [] });
        const fileList = files.map(f => {
            const stats = fs.statSync(path.join(pjDir, f));
            return { name: f, size: stats.size, modified: stats.mtime };
        });
        res.json({ files: fileList });
    });
});

app.use('/pj', express.static(pjDir));

// Route pour relancer les emails échoués
app.post('/retry-failed', async (req, res) => {
    // Vérifier si la session est validée
    if (!req.session.passwordValidated) {
        return res.status(401).json({ 
            success: false, 
            error: 'Session non authentifiée. Veuillez vous authentifier.' 
        });
    }
    
    res.json({ success: true, message: 'Relance initiée.' });
    
    io.emit('log', { type: 'system', message: '🔄 Relance des lots échoués...' });
    const failedEmails = loadFailedEmails();
    io.emit('log', { type: 'system', message: `${Object.keys(failedEmails).length} lots échoués trouvés` });
});

// Route pour vider le cache
app.post('/clear-cache', (req, res) => {
    io.emit('log', { type: 'system', message: `🧹 Cache vidé` });
    res.json({ success: true });
});

// Fonctions utilitaires
function generateRandomLetters(length) {
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    let result = '';
    for (let i = 0; i < length; i++) {
        result += characters.charAt(Math.floor(Math.random() * characters.length));
    }
    return result;
}

function generateRandomString(length) {
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < length; i++) {
        result += characters.charAt(Math.floor(Math.random() * characters.length));
    }
    return result;
}

function processPlaceholders(text, email = '') {
    if (!text) return '';
    let result = text.replace(/#random/g, () => generateRandomString(8));
    result = result.replace(/#email/g, email);
    result = result.replace(/\{RANDOM\}/g, () => generateRandomLetters(6));
    result = result.replace(/\{\{RANDOM_VALUE\}\}/g, () => generateRandomString(10));
    return result;
}

// === BOOLEAN PARSER ROBUSTE ===
function parseBoolean(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'string') {
        const v = value.toLowerCase().trim();
        return v === 'on' || v === 'true' || v === '1' || v === 'yes';
    }
    if (typeof value === 'number') return value === 1;
    return false;
}

// Route principale d'envoi (SANS vérification de mot de passe dans le body)
app.post('/send', async (req, res) => {
    // Vérifier si la session est validée
    if (!req.session.passwordValidated) {
        return res.status(401).json({ 
            success: false, 
            error: 'Session non authentifiée. Veuillez vous authentifier.' 
        });
    }
    
    // Vérifier si la session n'est pas trop vieille (24h max)
    const validationTime = req.session.validationTime;
    if (validationTime && (Date.now() - validationTime) > 24 * 60 * 60 * 1000) {
        req.session.passwordValidated = false;
        return res.status(401).json({ 
            success: false, 
            error: 'Session expirée. Veuillez vous réauthentifier.' 
        });
    }
    
    isPaused = false;
    isStopped = false;
    io.emit('status_update', { paused: false, stopped: false });
    
    if (!req.body.retry) {
        saveFailedEmails({});
        io.emit('failed_count', { count: 0 });
    }

    res.json({ success: true, message: 'Processus initié.' });

    const { 
        smtpHost, smtpPort, smtpUsername, smtpPassword,
        maillist, fromName, fromEmail, subject, message, customHeaders,
        attachmentFiles, replyTo, threads, useBcc, bccCount,
        emailDelay, testEmail, testInterval
    } = req.body;
    
    const useBccBool = parseBoolean(useBcc);
    const bccLimit = parseInt(bccCount) || 1;
    const threadLimit = parseInt(threads) || 1;
    const delayBetweenEmails = parseInt(emailDelay) || 0; // Délai en millisecondes
    let testIntervalValue = parseInt(testInterval) || 0;
    
    // Validation des paramètres SMTP
    if (!smtpHost || !smtpUsername || !smtpPassword) {
        io.emit('log', { type: 'error', message: '❌ Paramètres SMTP incomplets. Veuillez fournir host, username et password.' });
        return;
    }
    
    const smtpPortNumber = parseInt(smtpPort) || 587;
    const defaultFromName = fromName && fromName.trim() ? fromName : 'NeonMail';
    const defaultSubject = subject && subject.trim() ? subject : 'Sans Objet';
    const defaultMessage = message && message.trim() ? message : '<p>Pas de contenu</p>';

    io.emit('log', { type: 'system', message: `✅ Authentification validée. Accès accordé.` });
    io.emit('log', { type: 'system', message: `🚀 Initialisation avec ${threadLimit} threads...` });
    io.emit('log', { type: 'system', message: `⏱️ Délai entre les emails: ${delayBetweenEmails}ms` });
    
    (async () => {
        // Parse maillist
        const emails = maillist.split(/\r?\n/).map(e => e.trim()).filter(e => e);
        if (emails.length === 0) {
            io.emit('log', { type: 'error', message: '❌ La liste des emails est vide.' });
            return;
        }

        io.emit('log', { type: 'system', message: `🎯 Cibles: ${emails.length} destinataires.` });

        // Fonction d'envoi d'un email
        const sendEmail = async (recipient, isTest = false, retryCount = 0, maxRetries = 3) => {
            try {
                // Création du transporteur SMTP
                const transporter = nodemailer.createTransport({
                    host: smtpHost,
                    port: smtpPortNumber,
                    secure: smtpPortNumber === 465,
                    auth: {
                        user: smtpUsername,
                        pass: smtpPassword
                    },
                    tls: {
                        rejectUnauthorized: false
                    }
                });

                const processedFromName = processPlaceholders(defaultFromName, recipient);
                const processedFromEmail = processPlaceholders(fromEmail, recipient);
                const processedSubject = processPlaceholders(defaultSubject, recipient);
                const processedMessage = processPlaceholders(defaultMessage, recipient);

                let parsedHeaders = {};
                if (customHeaders) {
                    const headLines = customHeaders.split(/\r?\n/);
                    for (let hLine of headLines) {
                        const sepIdx = hLine.indexOf(':');
                        if (sepIdx !== -1) {
                            const key = hLine.substring(0, sepIdx).trim();
                            const valueInfo = hLine.substring(sepIdx + 1).trim();
                            const processedValue = processPlaceholders(valueInfo, recipient);
                            if (key && processedValue) parsedHeaders[key] = processedValue;
                        }
                    }
                }

                let attachments = [];
                if (attachmentFiles && Array.isArray(attachmentFiles)) {
                    for (let file of attachmentFiles) {
                        const filePath = path.join(pjDir, file.name);
                        if (fs.existsSync(filePath)) {
                            attachments.push({ filename: file.name, path: filePath });
                        }
                    }
                }

                const fromString = `"${processedFromName}" <${processedFromEmail}>`;
                
                const mailOptions = {
                    from: fromString,
                    to: isTest ? testEmail : recipient,
                    subject: processedSubject,
                    html: processedMessage,
                    textEncoding: 'quoted-printable',
                    headers: parsedHeaders,
                    replyTo: replyTo || undefined
                };
                
                if (attachments.length > 0) mailOptions.attachments = attachments;

                const info = await transporter.sendMail(mailOptions);
                
                return { success: true, id: info.messageId };
            } catch (error) {
                if (retryCount < maxRetries) {
                    io.emit('log', { type: 'warning', message: `🔄 Tentative ${retryCount + 1}/${maxRetries} pour ${recipient}: ${error.message}` });
                    await new Promise(r => setTimeout(r, 2000));
                    return sendEmail(recipient, isTest, retryCount + 1, maxRetries);
                }
                return { success: false, error: error.message };
            }
        };

        // Organisation des batches pour BCC
        let batches = [];
        if (useBccBool) {
            for (let i = 0; i < emails.length; i += bccLimit) {
                batches.push({ recipients: emails.slice(i, i + bccLimit), id: i });
            }
        } else {
            for (let i = 0; i < emails.length; i++) {
                batches.push({ recipients: [emails[i]], id: i });
            }
        }

        io.emit('update_totals', { total: emails.length, batches: batches.length });

        let sentCount = 0;
        let failCount = 0;
        const queue = [...batches];
        let nextTestThreshold = testIntervalValue;
        let autoPauseActive = false;
        let autoPausePromise = null;
        
        const pauseEvery = parseInt(req.body.pauseEvery) || 0;
        const pauseTime = parseInt(req.body.pauseTime) || 0;
        let nextPauseThreshold = pauseEvery > 0 ? pauseEvery : null;

        // Worker
        const worker = async (threadId) => {
            const workerName = `Thread${threadId}`;
            
            while (queue.length > 0 && !isStopped) {
                while (isPaused || autoPauseActive) {
                    if (isStopped) break;
                    if (autoPauseActive && autoPausePromise) {
                        try { await autoPausePromise; } catch(e) {}
                    } else {
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                }
                if (isStopped) break;

                const batch = queue.shift();
                if (!batch) break;

                // Envoi des emails du batch (en BCC ou individuel)
                if (useBccBool) {
                    // Envoi en BCC
                    const result = await sendEmail(batch.recipients[0], false);
                    
                    if (result.success) {
                        sentCount += batch.recipients.length;
                        io.emit('log', { type: 'success', message: `[${workerName}] ✓ Envoyé à ${batch.recipients.length} destinataires (Total: ${sentCount})` });
                    } else {
                        failCount += batch.recipients.length;
                        io.emit('log', { type: 'error', message: `[${workerName}] ✗ Échoué: ${result.error}` });
                        
                        const failedBatches = loadFailedEmails();
                        failedBatches[batch.id] = {
                            recipients: batch.recipients,
                            attempts: (failedBatches[batch.id]?.attempts || 0) + 1,
                            lastError: result.error,
                            lastAttempt: new Date().toISOString()
                        };
                        saveFailedEmails(failedBatches);
                    }
                } else {
                    // Envoi individuel
                    for (const recipient of batch.recipients) {
                        if (isStopped) break;
                        
                        const result = await sendEmail(recipient, false);
                        
                        if (result.success) {
                            sentCount++;
                            io.emit('log', { type: 'success', message: `[${workerName}] ✓ Envoyé à ${recipient} (Total: ${sentCount})` });
                        } else {
                            failCount++;
                            io.emit('log', { type: 'error', message: `[${workerName}] ✗ Échoué pour ${recipient}: ${result.error}` });
                            
                            const failedBatches = loadFailedEmails();
                            failedBatches[batch.id] = {
                                recipients: [recipient],
                                attempts: (failedBatches[batch.id]?.attempts || 0) + 1,
                                lastError: result.error,
                                lastAttempt: new Date().toISOString()
                            };
                            saveFailedEmails(failedBatches);
                        }

                        // Délai entre les emails
                        if (delayBetweenEmails > 0 && queue.length > 0) {
                            await new Promise(resolve => setTimeout(resolve, delayBetweenEmails));
                        }
                    }
                }

                // Test email
                if (testEmail && testIntervalValue > 0 && sentCount >= nextTestThreshold) {
                    const testResult = await sendEmail(testEmail, true);
                    if (testResult.success) {
                        io.emit('log', { type: 'success', message: `✅ Test envoyé à ${testEmail}` });
                    } else {
                        io.emit('log', { type: 'error', message: `❌ Test échoué: ${testResult.error}` });
                    }
                    nextTestThreshold += testIntervalValue;
                }

                // Pause automatique
                if (nextPauseThreshold && sentCount >= nextPauseThreshold && !autoPauseActive) {
                    autoPauseActive = true;
                    io.emit('log', { type: 'system', message: `⏸️ PAUSE ${pauseTime}s...` });
                    
                    autoPausePromise = new Promise(resolve => {
                        let seconds = pauseTime;
                        const interval = setInterval(() => {
                            if (isStopped || seconds <= 0) {
                                clearInterval(interval);
                                resolve();
                            }
                            seconds--;
                        }, 1000);
                    }).then(() => {
                        if (!isStopped) io.emit('log', { type: 'system', message: `▶️ Reprise` });
                        nextPauseThreshold += pauseEvery;
                        autoPauseActive = false;
                        autoPausePromise = null;
                    });
                    await autoPausePromise;
                }

                io.emit('progress', { sent: sentCount, failed: failCount, total: emails.length });
                io.emit('failed_count', { count: Object.keys(loadFailedEmails()).length });
            }
        };

        // Lancement des threads
        const workers = [];
        for (let tIdx = 0; tIdx < threadLimit; tIdx++) {
            workers.push(worker(tIdx + 1));
            await new Promise(r => setTimeout(r, 100));
        }

        await Promise.all(workers);
        
        const failedCount = Object.keys(loadFailedEmails()).length;
        io.emit('log', { type: 'system', message: `═══════════════════════════════════` });
        io.emit('log', { type: 'system', message: `✅ TERMINÉ - Envoyés: ${sentCount} | Échoués: ${failCount}` });
        if (failedCount > 0) {
            io.emit('log', { type: 'system', message: `💾 ${failedCount} lot(s) échoué(s) sauvegardé(s)` });
        }
        io.emit('log', { type: 'system', message: `═══════════════════════════════════` });
        io.emit('process_complete', { success: true });
    })();
});

server.listen(port, () => {
    console.log(`✅ Neon Server running at http://localhost:${port}`);
    console.log(`📧 Prêt à envoyer des emails avec authentification SMTP`);
    console.log(`⏱️ Délai modifiable entre les emails`);
    console.log(`🔐 Authentification unique par session (24h)`);
    console.log(`✨ "La Force soit avec vous, jeune Padawan." - Maître Yoda`);
});