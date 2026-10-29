import { del, put } from '@vercel/blob';
import { timingSafeEqual } from 'node:crypto';

const MAX_BODY_BYTES = 4 * 1024 * 1024 + 64 * 1024;
const MAX_IMAGE_BYTES = 3 * 1024 * 1024;
const IMAGE_TYPES = new Map([
  ['jpg', { contentType: 'image/jpeg', signature: (data) => data.length >= 3 && data[0] === 0xff && data[1] === 0xd8 && data[2] === 0xff }],
  ['jpeg', { contentType: 'image/jpeg', signature: (data) => data.length >= 3 && data[0] === 0xff && data[1] === 0xd8 && data[2] === 0xff }],
  ['png', { contentType: 'image/png', signature: (data) => data.length >= 8 && data.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])) }],
  ['gif', { contentType: 'image/gif', signature: (data) => data.subarray(0, 6).toString('ascii').match(/^GIF8[79]a$/) !== null }],
  ['webp', { contentType: 'image/webp', signature: (data) => data.length >= 12 && data.toString('ascii', 0, 4) === 'RIFF' && data.toString('ascii', 8, 12) === 'WEBP' }],
]);

function isAuthorized(request) {
  const expectedToken = process.env.BLOB_READ_WRITE_TOKEN;
  const authorization = request.headers.authorization ?? '';
  const suppliedToken = authorization.startsWith('Bearer ') ? authorization.slice(7) : '';
  if (!expectedToken || !suppliedToken) return false;

  const expected = Buffer.from(expectedToken);
  const supplied = Buffer.from(suppliedToken);
  return expected.length === supplied.length && timingSafeEqual(expected, supplied);
}

async function readJsonBody(request) {
  if (Buffer.isBuffer(request.body)) {
    return JSON.parse(request.body.toString('utf8'));
  }
  if (request.body && typeof request.body === 'object') {
    return request.body;
  }
  if (typeof request.body === 'string') {
    return JSON.parse(request.body);
  }

  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new Error('O arquivo excede o tamanho permitido.');
    chunks.push(chunk);
  }
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

export default async function handler(request, response) {
  response.setHeader('Cache-Control', 'no-store');
  if (request.method !== 'POST') {
    response.setHeader('Allow', 'POST');
    return response.status(405).json({ error: 'Método não permitido.' });
  }
  if (!isAuthorized(request)) {
    return response.status(401).json({ error: 'Não autorizado.' });
  }

  try {
    const body = await readJsonBody(request);
    if (!body || typeof body !== 'object' || Array.isArray(body)) {
      return response.status(400).json({ error: 'Corpo da requisição inválido.' });
    }

    if (body.action === 'upload') {
      if (
        typeof body.pathname !== 'string'
        || !/^(avatars\/[0-9]+|logos)\/[A-Za-z0-9_-]+\.(jpg|jpeg|png|gif|webp)$/i.test(body.pathname)
        || typeof body.data !== 'string'
        || body.data.length > Math.ceil(MAX_IMAGE_BYTES * 4 / 3) + 4
      ) {
        return response.status(400).json({ error: 'Dados de imagem inválidos.' });
      }

      const extension = body.pathname.split('.').pop().toLowerCase();
      const imageType = IMAGE_TYPES.get(extension);
      const image = Buffer.from(body.data, 'base64');
      if (
        !image.length
        || image.length > MAX_IMAGE_BYTES
        || image.toString('base64').replace(/=+$/, '') !== body.data.replace(/=+$/, '')
        || body.contentType !== imageType.contentType
        || !imageType.signature(image)
      ) {
        return response.status(400).json({ error: 'O conteúdo da imagem não corresponde ao formato informado.' });
      }

      const blob = await put(body.pathname, image, {
        access: 'public',
        addRandomSuffix: true,
        contentType: imageType.contentType,
      });
      return response.status(200).json({ url: blob.url });
    }

    if (body.action === 'delete') {
      if (
        typeof body.url !== 'string'
        || !/^https:\/\/[a-z0-9-]+\.public\.blob\.vercel-storage\.com\/[A-Za-z0-9/_.,%-]+$/i.test(body.url)
      ) {
        return response.status(400).json({ error: 'URL Blob inválida.' });
      }

      await del(body.url, { access: 'public' });
      return response.status(200).json({ deleted: true });
    }

    return response.status(400).json({ error: 'Ação Blob inválida.' });
  } catch (error) {
    console.error('Vercel Blob operation failed:', error);
    return response.status(500).json({ error: 'Não foi possível concluir a operação com o Blob.' });
  }
}
