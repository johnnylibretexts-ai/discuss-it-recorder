/* global importScripts */
// Classic worker is deliberate: MediaPipe's WASM loader uses importScripts.
// Runtime assets sit beside this script, so hosts may serve them from any directory.
self.exports = {}
importScripts(new URL('vision_bundle.js', self.location.href).href)
let segmenter
self.onmessage = async ({ data }) => {
  try {
    if (data.type === 'init') {
      const vision = self.exports
      const files = await vision.FilesetResolver.forVisionTasks(new URL('wasm', self.location.href).href)
      segmenter = await vision.ImageSegmenter.createFromOptions(files, {
        baseOptions: { modelAssetPath: new URL('selfie_segmenter.tflite', self.location.href).href, delegate: 'CPU' },
        runningMode: 'VIDEO',
        outputCategoryMask: false,
        outputConfidenceMasks: true
      })
      self.postMessage({ ready: true })
    } else {
      try {
        segmenter.segmentForVideo(data.frame, data.timestamp, result => {
          // Selfie segmenter exposes one confidence mask: confidence of person.
          const mask = result.confidenceMasks[0]
          const values = mask.getAsFloat32Array().slice()
          self.postMessage({ width: mask.width, height: mask.height, values: values.buffer }, [values.buffer])
        })
      } finally { data.frame.close() }
    }
  } catch (error) { self.postMessage({ error: 'Background processing is unavailable. Try again or choose None before recording.' }) }
}
