import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';

// Job order photos are read later: a modem's serial label, a meter reading, a signed contract.
// 1600px on the long side keeps that text sharp while a 4000px phone photo drops from several MB
// to a few hundred KB, which is what lets a set of them upload on a weak signal.
export const PHOTO_MAX_EDGE = 1600;
export const PHOTO_JPEG_QUALITY = 0.8;

export type PhotoFile = { uri: string; name: string; type: string };

/**
 * Shrinks a picked or captured photo to fit within PHOTO_MAX_EDGE on its long side and saves it
 * as a JPEG in the app cache. A photo already within the limit keeps its full resolution and is
 * only re-compressed: shrinking an image that is already small is what turns it into a blur.
 *
 * The original is untouched, so the copy saved to the phone's gallery stays full quality.
 */
export const optimizePhoto = async (photo: PhotoFile): Promise<PhotoFile> => {
  // Rendered once first to learn the real, orientation-corrected size of the image.
  const original = await ImageManipulator.manipulate(photo.uri).renderAsync();
  let image = original;

  try {
    const longEdge = Math.max(original.width, original.height);

    // Never enlarged: upscaling adds bytes and no detail.
    if (longEdge > PHOTO_MAX_EDGE) {
      image = await ImageManipulator.manipulate(original)
        .resize(original.width >= original.height ? { width: PHOTO_MAX_EDGE } : { height: PHOTO_MAX_EDGE })
        .renderAsync();
    }

    const saved = await image.saveAsync({ compress: PHOTO_JPEG_QUALITY, format: SaveFormat.JPEG });

    return {
      uri: saved.uri,
      name: `${photo.name.replace(/\.[^/.]+$/, '') || 'photo'}.jpg`,
      type: 'image/jpeg',
    };
  } finally {
    // Decoded bitmaps are large; free them now rather than whenever the GC gets to them.
    if (image !== original) image.release();
    original.release();
  }
};
