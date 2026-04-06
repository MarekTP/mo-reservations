(function(blocks, el, blockEditor, components){
  var InspectorControls = blockEditor.InspectorControls;
  var useBlockProps = blockEditor.useBlockProps;
  var PanelBody = components.PanelBody;
  var SelectControl = components.SelectControl;

  // Kalendáře z PHP (moresBlockData.calendars)
  var calOptions = (typeof moresBlockData !== 'undefined' && moresBlockData.calendars)
    ? moresBlockData.calendars
    : [{ value:1, label:'Kalendář #1' }];

  calOptions = calOptions.map(function(o){
    return { value: String(o.value), label: o.label };
  });

  blocks.registerBlockType('mo-reservations/calendar', {
    title: 'MO Rezervace',
    icon: 'calendar-alt',
    category: 'widgets',
    description: 'Rezervační kalendář s týdenním přehledem dostupnosti.',
    attributes: {
      calendar: { type:'number', default: parseInt(calOptions[0].value) || 1 }
    },
    supports: {
      align: ['wide','full'],
      spacing: { padding: true, margin: true },
      __experimentalBorder: { radius: true }
    },

    edit: function(props){
      var a = props.attributes;
      var blockProps = useBlockProps({
        style: { padding:'20px', background:'#f9f9f9', textAlign:'center',
                 border:'2px dashed #bbb', borderRadius:'8px' }
      });

      var calName = '';
      calOptions.forEach(function(o){
        if (String(o.value) === String(a.calendar)) calName = o.label;
      });

      return el('div', blockProps,
        el(InspectorControls, {},
          el(PanelBody, { title:'Kalendář', initialOpen:true },
            el(SelectControl, {
              label: 'Vyberte kalendář',
              value: String(a.calendar),
              options: calOptions,
              onChange: function(v){ props.setAttributes({ calendar: parseInt(v) || 1 }); }
            })
          )
        ),
        el('span', { className:'dashicons dashicons-calendar-alt',
                     style:{fontSize:'28px',color:'#06c'} }),
        el('p', { style:{margin:'.5rem 0 0',fontWeight:600} }, 'MO Rezervace'),
        el('small', { style:{color:'#555'} }, calName || ('Kalendář #' + a.calendar))
      );
    },

    save: function(){ return null; }
  });
})(wp.blocks, wp.element.createElement, wp.blockEditor, wp.components);
