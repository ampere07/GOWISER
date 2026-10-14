import apiClient from '../config/api';

export interface ImageSizeSetting {
  id: number;
  image_size: string;
  image_size_value: number;
  status: 'active' | 'inactive';
}

export const getActiveImageSize = async (): Promise<ImageSizeSetting | null> => {
  try {
    const response = await apiClient.get<{ success: boolean; data: ImageSizeSetting[] }>('/settings/image-size');
    if (response.data.success && Array.isArray(response.data.data)) {
      const activeSetting = response.data.data.find(setting => setting.status === 'active');
      return activeSetting || null;
    }
    return null;
  } catch (error) {
    return null;
  }
};

export const resizeImage = (file: File, resizePercentage: number): Promise<File> => {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    
    reader.onload = (e) => {
      const img = new Image();
      
      img.onload = () => {
        const canvas = document.createElement('canvas');
        const scaleFactor = resizePercentage / 100;
        
        canvas.width = Math.floor(img.width * scaleFactor);
        canvas.height = Math.floor(img.height * scaleFactor);
        
        const ctx = canvas.getContext('2d', { alpha: false });
        if (!ctx) {
          reject(new Error('Failed to get canvas context'));
          return;
        }
        
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        
        const outputType = file.type === 'image/png' ? 'image/jpeg' : file.type;
        const outputQuality = 0.85;
        
        canvas.toBlob(
          (blob) => {
            if (!blob) {
              reject(new Error('Failed to create blob'));
              return;
            }
            
            const extension = outputType === 'image/jpeg' ? '.jpg' : file.name.split('.').pop();
            const baseName = file.name.replace(/\.[^/.]+$/, '');
            const outputFileName = `${baseName}.${extension}`;
            
            const resizedFile = new File([blob], outputFileName, {
              type: outputType,
              lastModified: Date.now(),
            });
            
            resolve(resizedFile);
          },
          outputType,
          outputQuality
        );
      };
      
      img.onerror = () => reject(new Error('Failed to load image'));
      img.src = e.target?.result as string;
    };
    
    reader.onerror = () => reject(new Error('Failed to read file'));
    reader.readAsDataURL(file);
  });
};

// Job order photos are read later: a modem's serial label, a meter reading, a signed contract.
// 1600px on the long side keeps that text sharp while a 4000px phone photo drops from several MB
// to a few hundred KB, which is what lets a set of them upload on a weak signal.
export const PHOTO_MAX_EDGE = 1600;
export const PHOTO_JPEG_QUALITY = 0.82;

/**
 * Shrinks a photo to fit within maxEdge on its long side and re-encodes it as JPEG.
 *
 * Unlike resizeImage, which scales by a percentage, a photo already within the limit keeps its
 * full resolution: halving an image that is already small is what turns it into a blur.
 */
export const optimizePhoto = (
  file: File,
  maxEdge: number = PHOTO_MAX_EDGE,
  quality: number = PHOTO_JPEG_QUALITY
): Promise<File> => {
  return new Promise((resolve, reject) => {
    const sourceUrl = URL.createObjectURL(file);
    const img = new Image();

    img.onload = () => {
      URL.revokeObjectURL(sourceUrl);

      // Never enlarged: upscaling adds bytes and no detail.
      const scale = Math.min(1, maxEdge / Math.max(img.naturalWidth, img.naturalHeight));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.naturalWidth * scale);
      canvas.height = Math.round(img.naturalHeight * scale);

      const ctx = canvas.getContext('2d', { alpha: false });
      if (!ctx) {
        reject(new Error('Failed to get canvas context'));
        return;
      }

      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';
      // JPEG has no transparency; without a fill it would come out black.
      ctx.fillStyle = '#FFFFFF';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

      canvas.toBlob(
        (blob) => {
          if (!blob) {
            reject(new Error('Failed to encode image'));
            return;
          }

          const baseName = file.name.replace(/\.[^/.]+$/, '');
          resolve(new File([blob], `${baseName}.jpg`, { type: 'image/jpeg', lastModified: Date.now() }));
        },
        'image/jpeg',
        quality
      );
    };

    img.onerror = () => {
      URL.revokeObjectURL(sourceUrl);
      reject(new Error('Failed to load image'));
    };

    img.src = sourceUrl;
  });
};
